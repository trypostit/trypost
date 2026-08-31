<?php

declare(strict_types=1);

use App\Actions\Post\SyncPostPlatforms;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Jobs\PublishToSocialPlatform;
use App\Jobs\ReconcileGoogleBusinessProfilePost;
use App\Models\GoogleBusinessProfileLocation;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\GoogleBusinessProfile\GoogleBusinessProfileAnalytics;
use App\Services\Social\GoogleBusinessProfile\GoogleBusinessProfilePublisher;
use App\Support\PostPlatformMetaRules;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->account = SocialAccount::factory()->googleBusinessProfile()->create([
        'workspace_id' => $this->workspace->id,
        'access_token' => 'gbp-access-token',
        'token_expires_at' => now()->addHour(),
    ]);
    $this->location = GoogleBusinessProfileLocation::factory()->create([
        'social_account_id' => $this->account->id,
        'google_account_name' => 'accounts/123',
        'google_location_name' => 'locations/456',
        'title' => 'Downtown Store',
    ]);
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'A fresh update from Downtown Store.',
    ]);
});

test('sync creates one post target for each selected location without duplicating credentials', function (): void {
    GoogleBusinessProfileLocation::factory()->create([
        'social_account_id' => $this->account->id,
        'google_account_name' => 'accounts/123',
        'google_location_name' => 'locations/789',
        'title' => 'Uptown Store',
    ]);

    SyncPostPlatforms::execute($this->post);

    expect($this->post->postPlatforms()->count())->toBe(2)
        ->and($this->post->postPlatforms()->pluck('social_account_id')->unique()->all())->toBe([$this->account->id])
        ->and($this->post->postPlatforms()->pluck('google_business_profile_location_id')->filter()->count())->toBe(2);
});

test('publisher creates a standard local post for the selected location', function (): void {
    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/123/locations/456/localPosts' => Http::response([
            'name' => 'accounts/123/locations/456/localPosts/999',
            'state' => 'PROCESSING',
            'searchUrl' => 'https://posts.google.com/example',
        ]),
    ]);

    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'meta' => ['cta_action_type' => 'LEARN_MORE', 'cta_url' => 'https://example.com/details'],
    ]);

    $result = app(GoogleBusinessProfilePublisher::class)->publish($postPlatform);

    expect($result)->toMatchArray([
        'id' => 'accounts/123/locations/456/localPosts/999',
        'provider_state' => 'PROCESSING',
    ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://mybusiness.googleapis.com/v4/accounts/123/locations/456/localPosts'
        && $request['topicType'] === 'STANDARD'
        && $request['summary'] === 'A fresh update from Downtown Store.'
        && data_get($request->data(), 'callToAction.actionType') === 'LEARN_MORE');
});

test('temporary Google publish failures use the retryable platform unavailable path', function (): void {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limited']], 429)]);

    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
    ]);

    expect(fn () => app(GoogleBusinessProfilePublisher::class)->publish($postPlatform))
        ->toThrow(PlatformUnavailableException::class);
});

test('publisher maps offer timing recurrence and redemption fields', function (): void {
    Http::fake(['*' => Http::response(['name' => 'accounts/123/locations/456/localPosts/offer', 'state' => 'LIVE'])]);

    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'content_type' => ContentType::GoogleBusinessProfileOffer,
        'meta' => [
            'event_title' => 'Opening week offer',
            'event_start_at' => '2026-09-01T09:00:00-04:00',
            'event_end_at' => '2026-09-07T17:00:00-04:00',
            'offer_coupon_code' => 'OPEN20',
            'offer_redeem_url' => 'https://example.com/open20',
            'offer_terms' => 'One use per customer.',
            'recurrence_pattern' => 'weekly',
            'recurrence_days_of_week' => ['MONDAY', 'FRIDAY'],
            'recurrence_series_end_at' => '2026-10-01T00:00:00-04:00',
        ],
    ]);

    app(GoogleBusinessProfilePublisher::class)->publish($postPlatform);

    Http::assertSent(fn ($request): bool => $request['topicType'] === 'OFFER'
        && data_get($request->data(), 'event.title') === 'Opening week offer'
        && data_get($request->data(), 'event.recurrenceInfo.weeklyPattern.daysOfWeek') === ['MONDAY', 'FRIDAY']
        && data_get($request->data(), 'offer.couponCode') === 'OPEN20');
});

test('reconciliation only marks a processing post published after Google reports it live', function (): void {
    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/123/locations/456/localPosts/999' => Http::response([
            'name' => 'accounts/123/locations/456/localPosts/999',
            'state' => 'LIVE',
            'searchUrl' => 'https://posts.google.com/live',
        ]),
    ]);

    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'status' => Status::PendingReview,
        'platform_post_id' => 'accounts/123/locations/456/localPosts/999',
        'submitted_at' => now(),
    ]);

    app()->call([new ReconcileGoogleBusinessProfilePost($postPlatform), 'handle']);

    expect($postPlatform->fresh()->status)->toBe(Status::Published)
        ->and($postPlatform->fresh()->platform_url)->toBe('https://posts.google.com/live')
        ->and($postPlatform->fresh()->last_reconciled_at)->not->toBeNull();
});

test('reconciliation exhaustion marks the target failed without losing the Google post id', function (): void {
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'status' => Status::PendingReview,
        'platform_post_id' => 'accounts/123/locations/456/localPosts/failed-reconcile',
        'submitted_at' => now(),
    ]);

    (new ReconcileGoogleBusinessProfilePost($postPlatform))->failed(null);

    expect($postPlatform->fresh()->status)->toBe(Status::Failed)
        ->and($postPlatform->fresh()->platform_post_id)->toBe('accounts/123/locations/456/localPosts/failed-reconcile')
        ->and($postPlatform->fresh()->last_reconciled_at)->not->toBeNull();
});

test('publish job keeps a processing Google post nonterminal until reconciliation', function (): void {
    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/123/locations/456/localPosts' => Http::response([
            'name' => 'accounts/123/locations/456/localPosts/processing',
            'state' => 'PROCESSING',
            'searchUrl' => 'https://posts.google.com/processing',
        ]),
    ]);

    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'enabled' => true,
    ]);

    (new PublishToSocialPlatform($postPlatform))->handle();

    expect($postPlatform->fresh()->status)->toBe(Status::PendingReview)
        ->and($postPlatform->fresh()->platform_post_id)->toBe('accounts/123/locations/456/localPosts/processing')
        ->and($postPlatform->fresh()->published_at)->toBeNull();
});

test('analytics labels Google privacy thresholds as upper bounds', function (): void {
    Http::fake([
        'https://businessprofileperformance.googleapis.com/v1/locations/456:fetchMultiDailyMetricsTimeSeries*' => Http::response([
            'multiDailyMetricTimeSeries' => [],
        ]),
        'https://businessprofileperformance.googleapis.com/v1/locations/456/searchkeywords/impressions/monthly*' => Http::response([
            'searchKeywordsCounts' => [[
                'searchKeyword' => 'electrician near me',
                'insightsValue' => ['threshold' => '15'],
            ]],
        ]),
    ]);

    $metrics = app(GoogleBusinessProfileAnalytics::class)->getMetrics($this->account);

    expect(collect($metrics)->firstWhere('label', 'Search: electrician near me')['value'])->toBe('<15');
});

test('google business profile declares every local post content type', function (): void {
    expect(ContentType::forPlatform(Platform::GoogleBusinessProfile))->toHaveCount(4)
        ->and(ContentType::defaultFor(Platform::GoogleBusinessProfile))->toBe(ContentType::GoogleBusinessProfileStandard);
});

test('post metrics degrade cleanly when a historical target has no account or location', function (): void {
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->make([
        'post_id' => $this->post->id,
        'social_account_id' => null,
        'google_business_profile_location_id' => null,
        'platform_post_id' => 'accounts/123/locations/456/localPosts/historical',
    ]);

    expect(app(GoogleBusinessProfileAnalytics::class)->fetchPostMetrics($postPlatform))->toBe([
        'unsupported' => true,
        'reason' => 'missing_account_or_location',
    ]);
});

test('effective stored Google metadata is validated when scheduling without platform overrides', function (): void {
    PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'content_type' => ContentType::GoogleBusinessProfileEvent,
        'enabled' => true,
        'meta' => [],
    ]);

    $payloads = PostPlatformMetaRules::effectivePayloadsForUpdate($this->post, null);

    expect(fn () => PostPlatformMetaRules::assertGoogleBusinessProfilePayloads(
        $payloads,
        fn ($platform) => ContentType::tryFrom((string) data_get($platform, 'content_type')),
    ))->toThrow(ValidationException::class);
});
