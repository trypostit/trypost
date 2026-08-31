<?php

declare(strict_types=1);

use App\Actions\Post\CreatePost;
use App\Actions\Post\DuplicatePost;
use App\Actions\Post\SyncPostPlatforms;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
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
use App\Support\Social\GoogleBusinessProfileMediaDerivativeCleaner;
use App\Support\Social\PublishCheckpoint;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

test('creating a post rejects an ambiguous Google credential without a location', function (): void {
    GoogleBusinessProfileLocation::factory()->create([
        'social_account_id' => $this->account->id,
        'title' => 'Uptown Store',
    ]);

    expect(fn () => CreatePost::execute($this->workspace, $this->user, [
        'content' => 'Do not publish this everywhere.',
        'platforms' => [['social_account_id' => $this->account->id]],
    ]))->toThrow(ValidationException::class);
});

test('direct location selection callback provides a return-to-accounts fallback', function (): void {
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->get(route('app.social.google-business-profile.select-locations'))
        ->assertInertia(fn ($page) => $page
            ->component('accounts/PopupCallback')
            ->where('success', false)
            ->where('fallbackUrl', route('app.accounts'))
        );
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

test('local post media converts WebP to a public Google compliant JPEG derivative', function (): void {
    if (! function_exists('imagewebp')) {
        $this->markTestSkipped('GD WebP support is required for this conversion test.');
    }

    Storage::fake('public');
    config(['filesystems.default' => 'public']);

    $image = imagecreatetruecolor(300, 300);
    imagefill($image, 0, 0, imagecolorallocate($image, 87, 42, 170));
    ob_start();
    imagewebp($image, null, 90);
    $webp = (string) ob_get_clean();
    imagedestroy($image);
    Storage::put('posts/image.webp', $webp);

    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/123/locations/456/localPosts' => Http::response([
            'name' => 'accounts/123/locations/456/localPosts/media',
            'state' => 'PROCESSING',
        ]),
    ]);
    $this->post->update(['media' => [[
        'id' => 'image-1',
        'path' => 'posts/image.webp',
        'url' => 'https://cdn.example.com/posts/image.webp',
        'mime_type' => 'image/webp',
    ]]]);
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
    ]);

    $result = app(GoogleBusinessProfilePublisher::class)->publish($postPlatform);
    $derivativePath = $result['derivative_path'];

    Storage::assertExists($derivativePath);
    expect($derivativePath)->toStartWith('social-google-business-profile-media/')
        ->and($derivativePath)->toEndWith('.jpg')
        ->and(getimagesizefromstring(Storage::get($derivativePath))['mime'])->toBe('image/jpeg')
        ->and($postPlatform->fresh()->status)->toBe(Status::PendingReview)
        ->and(data_get($postPlatform->fresh()->error_context, PublishCheckpoint::GOOGLE_BUSINESS_PROFILE_DERIVATIVE_PATH))->toBe($derivativePath);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://mybusiness.googleapis.com/v4/accounts/123/locations/456/localPosts'
        && data_get($request->data(), 'media.0.mediaFormat') === 'PHOTO'
        && str_ends_with((string) data_get($request->data(), 'media.0.sourceUrl'), $derivativePath)
        && data_get($request->data(), 'media.0.sourceUrl') !== 'https://cdn.example.com/posts/image.webp');
});

test('retryable Google failures retain and reuse the same JPEG derivative', function (): void {
    if (! function_exists('imagewebp')) {
        $this->markTestSkipped('GD WebP support is required for this conversion test.');
    }

    Storage::fake('public');
    config(['filesystems.default' => 'public']);

    $image = imagecreatetruecolor(300, 300);
    ob_start();
    imagewebp($image, null, 90);
    $webp = (string) ob_get_clean();
    imagedestroy($image);
    Storage::put('posts/image.webp', $webp);

    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/123/locations/456/localPosts' => Http::sequence()
            ->push(['error' => ['message' => 'Backend error', 'status' => 'INTERNAL']], 500)
            ->push(['name' => 'accounts/123/locations/456/localPosts/retry', 'state' => 'PROCESSING']),
    ]);
    $this->post->update(['media' => [[
        'id' => 'image-1',
        'path' => 'posts/image.webp',
        'url' => 'https://cdn.example.com/posts/image.webp',
        'mime_type' => 'image/webp',
    ]]]);
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
    ]);

    $derivativePath = null;
    try {
        app(GoogleBusinessProfilePublisher::class)->publish($postPlatform);
        $this->fail('Expected a retryable Google failure.');
    } catch (PlatformUnavailableException $e) {
        $derivativePath = PublishCheckpoint::googleBusinessProfileDerivativePath($e->context);
        expect($derivativePath)->not->toBeNull()
            ->and(data_get($e->context, 'google_error_status'))->toBe('INTERNAL')
            ->and(data_get($e->context, 'raw_response'))->toContain('Backend error');
        Storage::assertExists($derivativePath);
        $postPlatform->update(['error_context' => $e->context]);
    }

    $result = app(GoogleBusinessProfilePublisher::class)->publish($postPlatform->fresh());

    expect($result['derivative_path'])->toBe($derivativePath);
    Http::assertSentCount(2);
});

test('payload inspection does not create a Google media derivative', function (): void {
    Storage::fake('public');
    config(['filesystems.default' => 'public']);
    $this->post->update(['media' => [[
        'id' => 'image-1',
        'path' => 'posts/image.webp',
        'url' => 'https://cdn.example.com/posts/image.webp',
        'mime_type' => 'image/webp',
    ]]]);
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
    ]);

    $payload = app(GoogleBusinessProfilePublisher::class)->payload($postPlatform);

    expect(data_get($payload, 'media.0.sourceUrl'))->toBe('https://cdn.example.com/posts/image.webp')
        ->and(Storage::allFiles(GoogleBusinessProfileMediaDerivativeCleaner::DIRECTORY))->toBe([]);
});

test('temporary media storage failures use the retryable platform unavailable path', function (): void {
    config(['filesystems.default' => 'public']);
    Storage::shouldReceive('exists')->once()->with('posts/image.webp')->andReturnTrue();
    Storage::shouldReceive('size')->once()->with('posts/image.webp')->andThrow(new RuntimeException('temporary storage outage'));
    $this->post->update(['media' => [[
        'id' => 'image-1',
        'path' => 'posts/image.webp',
        'url' => 'https://cdn.example.com/posts/image.webp',
        'mime_type' => 'image/webp',
    ]]]);
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
    ]);

    expect(fn () => app(GoogleBusinessProfilePublisher::class)->publish($postPlatform))
        ->toThrow(PlatformUnavailableException::class);
    Http::assertNothingSent();
});

test('temporary retained derivative storage failures remain retryable', function (): void {
    config(['filesystems.default' => 'public']);
    $derivativePath = GoogleBusinessProfileMediaDerivativeCleaner::DIRECTORY.'/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::shouldReceive('exists')->once()->with($derivativePath)->andThrow(new RuntimeException('temporary storage outage'));
    $this->post->update(['media' => [[
        'id' => 'image-1',
        'path' => 'posts/image.webp',
        'url' => 'https://cdn.example.com/posts/image.webp',
        'mime_type' => 'image/webp',
    ]]]);
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'error_context' => [
            'category' => 'platform_unavailable',
            PublishCheckpoint::GOOGLE_BUSINESS_PROFILE_DERIVATIVE_PATH => $derivativePath,
        ],
    ]);

    expect(fn () => app(GoogleBusinessProfilePublisher::class)->publish($postPlatform))
        ->toThrow(PlatformUnavailableException::class);
    Http::assertNothingSent();
});

test('automatic Google retry exhaustion removes the retained JPEG derivative', function (): void {
    Queue::fake([SendNotification::class]);
    Storage::fake('public');
    config(['filesystems.default' => 'public']);

    $derivativePath = GoogleBusinessProfileMediaDerivativeCleaner::DIRECTORY.'/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::put($derivativePath, 'retained JPEG derivative');

    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/123/locations/456/localPosts' => Http::response([
            'error' => ['message' => 'Backend error', 'status' => 'INTERNAL'],
        ], 500),
    ]);
    $this->post->update(['media' => [[
        'id' => 'image-1',
        'path' => 'posts/image.webp',
        'url' => 'https://cdn.example.com/posts/image.webp',
        'mime_type' => 'image/webp',
    ]]]);
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'error_context' => [
            'category' => 'platform_unavailable',
            'retry_count' => 1,
            'max_retries' => 1,
            PublishCheckpoint::GOOGLE_BUSINESS_PROFILE_DERIVATIVE_PATH => $derivativePath,
        ],
    ]);

    (new PublishToSocialPlatform($postPlatform))->handle();

    $postPlatform->refresh();
    expect($postPlatform->status)->toBe(Status::Failed)
        ->and(data_get($postPlatform->error_context, PublishCheckpoint::GOOGLE_BUSINESS_PROFILE_DERIVATIVE_PATH))->toBeNull()
        ->and($postPlatform->error_context['retries_exhausted'] ?? null)->toBeTrue();
    Storage::assertMissing($derivativePath);
});

test('local post video identifies the media format Google must fetch', function (): void {
    Http::fake(['*' => Http::response(['name' => 'accounts/123/locations/456/localPosts/video', 'state' => 'LIVE'])]);
    $this->post->update(['media' => [[
        'id' => 'video-1',
        'path' => 'posts/video.mp4',
        'url' => 'https://cdn.example.com/posts/video.mp4',
        'mime_type' => 'video/mp4',
    ]]]);
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
    ]);

    app(GoogleBusinessProfilePublisher::class)->publish($postPlatform);

    Http::assertSent(fn ($request): bool => data_get($request->data(), 'media.0') === [
        'mediaFormat' => 'VIDEO',
        'sourceUrl' => 'https://cdn.example.com/posts/video.mp4',
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

test('publisher refuses legacy alert authoring', function (): void {
    Http::fake();
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'content_type' => ContentType::GoogleBusinessProfileAlert,
        'meta' => ['alert_type' => 'COVID_19'],
    ]);

    expect(fn () => app(GoogleBusinessProfilePublisher::class)->publish($postPlatform))
        ->toThrow(GoogleBusinessProfilePublishException::class);
    Http::assertNothingSent();
});

test('reconciliation only marks a processing post published after Google reports it live', function (): void {
    Storage::fake('public');
    config(['filesystems.default' => 'public']);
    $derivativePath = GoogleBusinessProfileMediaDerivativeCleaner::DIRECTORY.'/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::put($derivativePath, 'temporary image');

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
        'error_context' => [PublishCheckpoint::GOOGLE_BUSINESS_PROFILE_DERIVATIVE_PATH => $derivativePath],
    ]);

    app()->call([new ReconcileGoogleBusinessProfilePost($postPlatform), 'handle']);

    expect($postPlatform->fresh()->status)->toBe(Status::Published)
        ->and($postPlatform->fresh()->platform_url)->toBe('https://posts.google.com/live')
        ->and($postPlatform->fresh()->last_reconciled_at)->not->toBeNull();
    Storage::assertMissing($derivativePath);
});

test('event and offer reconciliation reaches a terminal status and sends one notification', function (
    ContentType $contentType,
    string $providerState,
    Status $expectedTargetStatus,
    PostStatus $expectedPostStatus,
    string $expectedNotificationTitle,
): void {
    Queue::fake([SendNotification::class]);
    Http::fake(['*' => Http::response([
        'name' => 'accounts/123/locations/456/localPosts/terminal',
        'state' => $providerState,
        'searchUrl' => 'https://posts.google.com/terminal',
    ])]);
    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'content_type' => $contentType,
        'status' => Status::PendingReview,
        'platform_post_id' => 'accounts/123/locations/456/localPosts/terminal',
        'submitted_at' => now(),
    ]);

    app()->call([new ReconcileGoogleBusinessProfilePost($postPlatform), 'handle']);

    expect($postPlatform->fresh()->status)->toBe($expectedTargetStatus)
        ->and($this->post->fresh()->status)->toBe($expectedPostStatus)
        ->and($postPlatform->fresh()->last_reconciled_at)->not->toBeNull();
    Queue::assertPushed(SendNotification::class, fn (SendNotification $notification): bool => $notification->title === $expectedNotificationTitle);
    Queue::assertPushed(SendNotification::class, 1);
})->with([
    'event accepted' => [
        ContentType::GoogleBusinessProfileEvent,
        'LIVE',
        Status::Published,
        PostStatus::Published,
        'Post published successfully',
    ],
    'offer rejected' => [
        ContentType::GoogleBusinessProfileOffer,
        'REJECTED',
        Status::Rejected,
        PostStatus::Failed,
        'Post failed to publish',
    ],
]);

test('multi-location reconciliation finalizes a mixed result as partially published', function (): void {
    Queue::fake([SendNotification::class]);
    $secondLocation = GoogleBusinessProfileLocation::factory()->create([
        'social_account_id' => $this->account->id,
        'google_account_name' => 'accounts/123',
        'google_location_name' => 'locations/789',
        'title' => 'Uptown Store',
    ]);
    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/123/locations/456/localPosts/live' => Http::response([
            'name' => 'accounts/123/locations/456/localPosts/live',
            'state' => 'LIVE',
            'searchUrl' => 'https://posts.google.com/live',
        ]),
        'https://mybusiness.googleapis.com/v4/accounts/123/locations/789/localPosts/rejected' => Http::response([
            'name' => 'accounts/123/locations/789/localPosts/rejected',
            'state' => 'REJECTED',
        ]),
    ]);
    $publishedTarget = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'status' => Status::PendingReview,
        'platform_post_id' => 'accounts/123/locations/456/localPosts/live',
    ]);
    $rejectedTarget = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $secondLocation->id,
        'status' => Status::PendingReview,
        'platform_post_id' => 'accounts/123/locations/789/localPosts/rejected',
    ]);

    app()->call([new ReconcileGoogleBusinessProfilePost($publishedTarget), 'handle']);
    expect($this->post->fresh()->status)->not->toBeIn([
        PostStatus::Published,
        PostStatus::PartiallyPublished,
        PostStatus::Failed,
    ]);

    app()->call([new ReconcileGoogleBusinessProfilePost($rejectedTarget), 'handle']);

    expect($publishedTarget->fresh()->status)->toBe(Status::Published)
        ->and($rejectedTarget->fresh()->status)->toBe(Status::Rejected)
        ->and($this->post->fresh()->status)->toBe(PostStatus::PartiallyPublished);
    Queue::assertPushed(SendNotification::class, fn (SendNotification $notification): bool => $notification->title === 'Post failed to publish'
        && str_contains($notification->body, 'Uptown Store'));
    Queue::assertPushed(SendNotification::class, 1);
});

test('reconciliation exhaustion marks the target failed without losing the Google post id', function (): void {
    Storage::fake('public');
    config(['filesystems.default' => 'public']);
    $derivativePath = GoogleBusinessProfileMediaDerivativeCleaner::DIRECTORY.'/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::put($derivativePath, 'temporary image');

    $postPlatform = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'status' => Status::PendingReview,
        'platform_post_id' => 'accounts/123/locations/456/localPosts/failed-reconcile',
        'submitted_at' => now(),
        'error_context' => [PublishCheckpoint::GOOGLE_BUSINESS_PROFILE_DERIVATIVE_PATH => $derivativePath],
    ]);

    (new ReconcileGoogleBusinessProfilePost($postPlatform))->failed(null);

    expect($postPlatform->fresh()->status)->toBe(Status::Failed)
        ->and($postPlatform->fresh()->platform_post_id)->toBe('accounts/123/locations/456/localPosts/failed-reconcile')
        ->and($postPlatform->fresh()->last_reconciled_at)->not->toBeNull();
    Storage::assertMissing($derivativePath);
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

test('analytics selector exposes each business location instead of the Google login', function (): void {
    config(['trypost.self_hosted' => true]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    GoogleBusinessProfileLocation::factory()->create([
        'social_account_id' => $this->account->id,
        'title' => 'Uptown Store',
    ]);

    $response = $this->actingAs($this->user)->get(route('app.analytics'));
    $response->assertOk();

    $accounts = collect($response->original->getData()['page']['props']['accounts'])
        ->where('platform', Platform::GoogleBusinessProfile->value)
        ->values();

    expect($accounts)->toHaveCount(2)
        ->and($accounts->pluck('display_label')->all())->toBe(['Downtown Store', 'Uptown Store'])
        ->and($accounts->pluck('account_id')->unique()->all())->toBe([$this->account->id])
        ->and($accounts->pluck('location_id')->filter())->toHaveCount(2);
});

test('google business profile declares every local post content type', function (): void {
    expect(ContentType::forPlatform(Platform::GoogleBusinessProfile))->toHaveCount(3)
        ->and(ContentType::forPlatform(Platform::GoogleBusinessProfile))->not->toContain(ContentType::GoogleBusinessProfileAlert)
        ->and(ContentType::defaultFor(Platform::GoogleBusinessProfile))->toBe(ContentType::GoogleBusinessProfileStandard);
});

test('disconnecting one location preserves its target and other locations', function (): void {
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $otherLocation = GoogleBusinessProfileLocation::factory()->create([
        'social_account_id' => $this->account->id,
        'title' => 'Uptown Store',
    ]);
    $scheduledPost = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scheduled_at' => now()->addDay(),
    ]);
    $target = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $scheduledPost->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'status' => Status::Pending,
        'enabled' => true,
    ]);
    $facebookAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
    ]);
    $otherTarget = PostPlatform::factory()->create([
        'post_id' => $scheduledPost->id,
        'social_account_id' => $facebookAccount->id,
        'platform' => Platform::Facebook,
        'status' => Status::Pending,
        'enabled' => true,
    ]);

    $this->actingAs($this->user)
        ->delete(route('app.social.google-business-profile.locations.disconnect', $this->location))
        ->assertRedirect();

    expect($this->account->fresh())->not->toBeNull()
        ->and($this->location->fresh()->is_selected)->toBeFalse()
        ->and($otherLocation->fresh()->is_selected)->toBeTrue()
        ->and($target->fresh()->enabled)->toBeFalse()
        ->and(data_get($target->fresh()->error_context, 'reason'))->toBe('gbp_location_disconnected')
        ->and($otherTarget->fresh()->enabled)->toBeTrue()
        ->and($scheduledPost->fresh()->status)->toBe(PostStatus::Draft)
        ->and($scheduledPost->fresh()->scheduled_at)->toBeNull();
});

test('a user cannot disconnect a Google Business Profile location from another workspace', function (): void {
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $foreignWorkspace = Workspace::factory()->create();
    $foreignAccount = SocialAccount::factory()->googleBusinessProfile()->create([
        'workspace_id' => $foreignWorkspace->id,
    ]);
    $foreignLocation = GoogleBusinessProfileLocation::factory()->create([
        'social_account_id' => $foreignAccount->id,
        'is_selected' => true,
    ]);
    $foreignPost = Post::factory()->create([
        'workspace_id' => $foreignWorkspace->id,
    ]);
    $foreignTarget = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $foreignPost->id,
        'social_account_id' => $foreignAccount->id,
        'google_business_profile_location_id' => $foreignLocation->id,
        'enabled' => true,
    ]);

    $this->actingAs($this->user)
        ->delete(route('app.social.google-business-profile.locations.disconnect', $foreignLocation))
        ->assertNotFound();

    expect($foreignLocation->fresh()->is_selected)->toBeTrue()
        ->and($foreignTarget->fresh()->enabled)->toBeTrue()
        ->and($foreignAccount->fresh()->status)->toBe(App\Enums\SocialAccount\Status::Connected);
});

test('disconnecting the final location clears the OAuth credential but preserves identity rows', function (): void {
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->account->update(['refresh_token' => 'refresh-token']);
    $target = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'status' => Status::Pending,
        'enabled' => true,
    ]);

    $this->actingAs($this->user)
        ->delete(route('app.social.google-business-profile.locations.disconnect', $this->location))
        ->assertRedirect();

    $account = $this->account->fresh();

    expect($account)->not->toBeNull()
        ->and($account->status)->toBe(App\Enums\SocialAccount\Status::Disconnected)
        ->and($account->access_token)->toBe('')
        ->and($account->refresh_token)->toBeNull()
        ->and($account->disconnected_at)->not->toBeNull()
        ->and($this->location->fresh())->not->toBeNull()
        ->and($this->location->fresh()->is_selected)->toBeFalse()
        ->and($target->fresh()->connection_issue_code)->toBe('gbp_location_disconnected');
});

test('reselecting a disconnected location restores only targets disabled by that disconnect', function (): void {
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $target = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'status' => Status::Pending,
        'enabled' => true,
    ]);

    $this->actingAs($this->user)
        ->delete(route('app.social.google-business-profile.locations.disconnect', $this->location));

    $this->withSession([
        'social_connect_workspace' => $this->workspace->id,
        'google_business_profile_account_id' => $this->account->id,
    ])->post(route('app.social.google-business-profile.select'), [
        'location_ids' => [$this->location->id],
    ])->assertOk();

    expect($this->location->fresh()->is_selected)->toBeTrue()
        ->and($target->fresh()->enabled)->toBeTrue()
        ->and($target->fresh()->error_message)->toBeNull()
        ->and($target->fresh()->error_context)->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Draft);
});

test('serialized Google Business Profile target exposes location identity and connection issue', function (): void {
    $target = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'platform_name' => 'Downtown Store',
        'platform_username' => 'DT-01',
    ]);

    expect($target->fresh()->toArray())
        ->toMatchArray([
            'display_name' => 'Downtown Store',
            'display_username' => 'DT-01',
            'connection_issue_code' => null,
        ]);

    $this->location->update(['is_selected' => false]);

    expect($target->fresh()->toArray()['connection_issue_code'])->toBe('gbp_location_disconnected');
});

test('terminal Google Business Profile history is not marked as needing reconnection', function (Status $status): void {
    $target = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'status' => $status,
    ]);

    $this->location->update(['is_selected' => false]);

    expect($target->fresh()->connection_issue_code)->toBeNull();
})->with([
    'published' => Status::Published,
    'failed' => Status::Failed,
    'rejected' => Status::Rejected,
]);

test('duplicating a legacy Google Business Profile alert creates an authorable standard draft', function (): void {
    PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'content_type' => ContentType::GoogleBusinessProfileAlert,
        'meta' => [
            'alert_type' => 'COVID_19',
            'cta_action_type' => 'LEARN_MORE',
            'cta_url' => 'https://example.com',
        ],
    ]);

    $duplicate = DuplicatePost::execute($this->post, $this->user);
    $duplicatedTarget = $duplicate->postPlatforms()->sole();

    expect($duplicatedTarget->content_type)->toBe(ContentType::GoogleBusinessProfileStandard)
        ->and($duplicatedTarget->meta)->not->toHaveKey('alert_type')
        ->and($duplicatedTarget->meta)->toMatchArray([
            'cta_action_type' => 'LEARN_MORE',
            'cta_url' => 'https://example.com',
        ]);
});

test('duplicating and editing a scheduled Google post never mutates the source', function (): void {
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $scheduledAt = now()->addDay()->startOfSecond();
    $this->post->update([
        'status' => PostStatus::Scheduled,
        'scheduled_at' => $scheduledAt,
    ]);
    $sourceTarget = PostPlatform::factory()->googleBusinessProfile()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'google_business_profile_location_id' => $this->location->id,
        'enabled' => true,
        'meta' => ['cta_action_type' => 'LEARN_MORE', 'cta_url' => 'https://example.com/source'],
    ]);

    $this->actingAs($this->user)
        ->post(route('app.posts.duplicate', $this->post))
        ->assertRedirect();

    $duplicate = $this->workspace->posts()->whereKeyNot($this->post->id)->latest('created_at')->firstOrFail();
    $duplicateTarget = $duplicate->postPlatforms()->sole();

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $duplicate), [
            'status' => PostStatus::Draft->value,
            'scheduled_at' => null,
            'content' => 'Edited duplicate content',
            'platforms' => [[
                'id' => $duplicateTarget->id,
                'content_type' => ContentType::GoogleBusinessProfileStandard->value,
                'meta' => ['cta_url' => 'https://example.com/duplicate'],
            ]],
        ])
        ->assertRedirect();

    expect($duplicate->fresh())
        ->status->toBe(PostStatus::Draft)
        ->scheduled_at->toBeNull()
        ->content->toBe('Edited duplicate content')
        ->and($duplicateTarget->fresh())
        ->id->not->toBe($sourceTarget->id)
        ->google_business_profile_location_id->toBe($this->location->id)
        ->meta->toMatchArray(['cta_url' => 'https://example.com/duplicate'])
        ->and($this->post->fresh())
        ->status->toBe(PostStatus::Scheduled)
        ->scheduled_at->toEqual($scheduledAt)
        ->content->toBe('A fresh update from Downtown Store.')
        ->and($sourceTarget->fresh()->meta)
        ->toMatchArray(['cta_url' => 'https://example.com/source']);
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
