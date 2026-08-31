<?php

declare(strict_types=1);

use App\Actions\Post\SyncPostPlatforms;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\GoogleBusinessProfilePublishException;
use App\Exceptions\TokenExpiredException;
use App\Jobs\PublishToSocialPlatform;
use App\Jobs\ReconcileGoogleBusinessProfilePost;
use App\Jobs\SendNotification;
use App\Models\GoogleBusinessProfileLocation;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\GoogleBusinessProfile\GoogleBusinessProfileAnalytics;
use App\Services\Social\GoogleBusinessProfile\GoogleBusinessProfilePublisher;
use App\Support\PostPlatformMetaRules;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

test('publisher refuses a location that was deselected after a draft was created', function (): void {
    Http::fake();
    $this->location->update(['is_selected' => false]);

    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
    ]);

    expect(fn () => app(GoogleBusinessProfilePublisher::class)->publish($postPlatform))
        ->toThrow(GoogleBusinessProfilePublishException::class);
    Http::assertNothingSent();
});

test('location target display identity uses its location snapshot instead of the Google login', function (): void {
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'platform_name' => 'Downtown Store',
        'platform_username' => 'DT-01',
    ]);

    expect($postPlatform->display_name)->toBe('Downtown Store')
        ->and($postPlatform->display_username)->toBe('DT-01');
});

test('saving location selection disables unpublished targets for deselected locations', function (): void {
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $replacement = GoogleBusinessProfileLocation::factory()->create([
        'social_account_id' => $this->account->id,
        'title' => 'Uptown Store',
    ]);
    $pending = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'status' => Status::Pending,
        'enabled' => true,
    ]);
    $submittedPost = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $submitted = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $submittedPost->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'status' => Status::PendingReview,
        'enabled' => true,
    ]);
    $publishingPost = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
    ]);
    $retrying = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $publishingPost->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'status' => Status::Retrying,
        'enabled' => true,
    ]);
    $scheduledPost = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scheduled_at' => now()->addDay(),
    ]);
    PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $scheduledPost->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'status' => Status::Pending,
        'enabled' => true,
    ]);

    $this->actingAs($this->user)
        ->withSession([
            'social_connect_workspace' => $this->workspace->id,
            'google_business_profile_account_id' => $this->account->id,
        ])
        ->post(route('app.social.google-business-profile.select'), [
            'location_ids' => [$replacement->id],
        ])
        ->assertOk();

    expect($this->location->fresh()->is_selected)->toBeFalse()
        ->and($replacement->fresh()->is_selected)->toBeTrue()
        ->and($pending->fresh()->enabled)->toBeFalse()
        ->and($submitted->fresh()->enabled)->toBeTrue()
        ->and($retrying->fresh()->enabled)->toBeTrue()
        ->and($scheduledPost->fresh()->status)->toBe(PostStatus::Draft)
        ->and($scheduledPost->fresh()->scheduled_at)->toBeNull();
});

test('local post media sends only the source URL accepted by Google', function (): void {
    Http::fake(['*' => Http::response(['name' => 'accounts/123/locations/456/localPosts/media', 'state' => 'LIVE'])]);
    $this->post->update(['media' => [[
        'id' => 'image-1',
        'path' => 'posts/image.jpg',
        'url' => 'https://cdn.example.com/posts/image.jpg',
        'mime_type' => 'image/jpeg',
    ]]]);
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
    ]);

    app(GoogleBusinessProfilePublisher::class)->publish($postPlatform);

    Http::assertSent(fn ($request): bool => data_get($request->data(), 'media.0') === [
        'sourceUrl' => 'https://cdn.example.com/posts/image.jpg',
    ]);
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
        && data_get($request->data(), 'event.schedule.startTime.hours') === 9
        && data_get($request->data(), 'event.schedule.endTime.hours') === 17
        && data_get($request->data(), 'event.recurrenceInfo.weeklyPattern.daysOfWeek') === ['MONDAY', 'FRIDAY']
        && data_get($request->data(), 'offer.couponCode') === 'OPEN20');
});

test('publisher maps event schedule and title', function (): void {
    Http::fake(['*' => Http::response(['name' => 'accounts/123/locations/456/localPosts/event', 'state' => 'LIVE'])]);
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'content_type' => ContentType::GoogleBusinessProfileEvent,
        'meta' => [
            'event_title' => 'Grand opening',
            'event_start_at' => '2026-09-01T09:30:00',
            'event_end_at' => '2026-09-01T11:00:00',
        ],
    ]);

    app(GoogleBusinessProfilePublisher::class)->publish($postPlatform);

    Http::assertSent(fn ($request): bool => $request['topicType'] === 'EVENT'
        && data_get($request->data(), 'event.title') === 'Grand opening'
        && data_get($request->data(), 'event.schedule.startTime.hours') === 9
        && data_get($request->data(), 'event.schedule.startTime.minutes') === 30);
});

test('publisher maps alert type', function (): void {
    Http::fake(['*' => Http::response(['name' => 'accounts/123/locations/456/localPosts/alert', 'state' => 'LIVE'])]);
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'content_type' => ContentType::GoogleBusinessProfileAlert,
        'meta' => ['alert_type' => 'COVID_19'],
    ]);

    app(GoogleBusinessProfilePublisher::class)->publish($postPlatform);

    Http::assertSent(fn ($request): bool => $request['topicType'] === 'ALERT'
        && $request['alertType'] === 'COVID_19');
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

test('reconciliation is unique per target and sends the standard failure notification for rejection', function (): void {
    Queue::fake([SendNotification::class]);
    Http::fake(['*' => Http::response([
        'name' => 'accounts/123/locations/456/localPosts/rejected',
        'state' => 'REJECTED',
    ])]);
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'platform_name' => 'Downtown Store',
        'status' => Status::PendingReview,
        'platform_post_id' => 'accounts/123/locations/456/localPosts/rejected',
    ]);
    $job = new ReconcileGoogleBusinessProfilePost($postPlatform);

    app()->call([$job, 'handle']);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe($postPlatform->id)
        ->and($job->uniqueFor)->toBe(7200)
        ->and($this->post->fresh()->status->value)->toBe('failed');
    Queue::assertPushed(SendNotification::class, fn (SendNotification $notification): bool => str_contains($notification->body, 'Downtown Store'));
    Queue::assertPushed(SendNotification::class, 1);
});

test('Google Business Profile permission denial is treated as a reconnectable credential failure', function (): void {
    Http::fake(['https://mybusinessaccountmanagement.googleapis.com/v1/accounts*' => Http::response([
        'error' => ['status' => 'PERMISSION_DENIED'],
    ], 403)]);

    expect(fn () => app(ConnectionVerifier::class)->verifyAccessToken($this->account))
        ->toThrow(TokenExpiredException::class);
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

test('publish job never executes a target disabled after it was queued', function (): void {
    Http::fake();
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'enabled' => false,
        'status' => Status::Pending,
    ]);

    (new PublishToSocialPlatform($postPlatform))->handle();

    expect($postPlatform->fresh()->status)->toBe(Status::Pending);
    Http::assertNothingSent();
});

test('stale child job never republishes a Google post already submitted for review', function (): void {
    Http::fake();
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'enabled' => true,
        'status' => Status::PendingReview,
        'platform_post_id' => 'accounts/123/locations/456/localPosts/already-submitted',
    ]);

    (new PublishToSocialPlatform($postPlatform))->handle();

    expect($postPlatform->fresh()->status)->toBe(Status::PendingReview);
    Http::assertNothingSent();
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
