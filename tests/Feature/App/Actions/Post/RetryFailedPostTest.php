<?php

declare(strict_types=1);

use App\Actions\Post\RetryFailedPost;
use App\Enums\Post\PublishStatus as PlatformStatus;
use App\Enums\Post\RetryOutcome;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Exceptions\Social\ErrorCategory;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
});

test('it queues a fresh attempt for a failed post and clears its stale result', function () {
    Bus::fake([PublishToSocialPlatform::class]);

    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $failedThreads = Post::factory()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'platform_post_id' => 'stale-post-id',
        'platform_url' => 'https://threads.net/stale',
        'published_at' => now()->subHour(),
        'error_context' => ['remote_operation_id' => 'stale-operation'],
    ]);

    expect(app(RetryFailedPost::class)->execute($failedThreads->fresh()))->toBe(RetryOutcome::Queued);

    expect($failedThreads->fresh()->status)->toBe(PostStatus::Publishing)
        ->and($failedThreads->fresh()->publish_status)->toBe(PlatformStatus::Pending)
        ->and($failedThreads->fresh()->platform_post_id)->toBeNull()
        ->and($failedThreads->fresh()->platform_url)->toBeNull()
        ->and($failedThreads->fresh()->published_at)->toBeNull()
        ->and($failedThreads->fresh()->error_message)->toBeNull()
        ->and($failedThreads->fresh()->error_context)->toBeNull();

    Bus::assertDispatchedTimes(PublishToSocialPlatform::class, 1);
    Bus::assertDispatched(
        PublishToSocialPlatform::class,
        fn (PublishToSocialPlatform $job): bool => $job->post->is($failedThreads) && $job->uniqueAttempt === 0,
    );
});

test('it resumes a TikTok publish_id instead of starting from scratch', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Storage::fake();

    $derivativePath = 'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::put($derivativePath, 'temporary image');
    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $failedTikTok = Post::factory()->tiktok()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'error_context' => [
            'tiktok_publish_id' => 'stale-publish-id',
            'tiktok_derivative_paths' => [$derivativePath],
            'retry_count' => 120,
            'max_retries' => 120,
            'category' => 'platform_unavailable',
        ],
    ]);

    expect(app(RetryFailedPost::class)->execute($failedTikTok->fresh()))->toBe(RetryOutcome::Queued);

    Storage::assertExists($derivativePath);
    expect($failedTikTok->fresh()->publish_status)->toBe(PlatformStatus::Pending)
        ->and($failedTikTok->fresh()->error_context)->toEqual([
            'tiktok_publish_id' => 'stale-publish-id',
            'tiktok_derivative_paths' => [$derivativePath],
        ]);

    Bus::assertDispatched(PublishToSocialPlatform::class, fn (PublishToSocialPlatform $job): bool => $job->post->is($failedTikTok));
});

test('it resumes a TikTok publish_id after an account or job interruption', function (ErrorCategory $category) {
    Bus::fake([PublishToSocialPlatform::class]);

    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $failedTikTok = Post::factory()->tiktok()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'error_context' => [
            'tiktok_publish_id' => 'pub_in_flight',
            'category' => $category->value,
        ],
    ]);

    expect(app(RetryFailedPost::class)->execute($failedTikTok->fresh()))->toBe(RetryOutcome::Queued);

    expect($failedTikTok->fresh()->publish_status)->toBe(PlatformStatus::Pending)
        ->and($failedTikTok->fresh()->error_context)->toEqual([
            'tiktok_publish_id' => 'pub_in_flight',
        ]);
})->with([
    'token expired' => [ErrorCategory::TokenExpired],
    'job failed' => [ErrorCategory::JobFailed],
]);

test('it keeps an Instagram workflow checkpoint on retry', function () {
    Bus::fake([PublishToSocialPlatform::class]);

    $workflow = [
        'stage' => 'final_container',
        'container_id' => 'container-123',
    ];
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $failedInstagram = Post::factory()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'error_context' => [
            'instagram_workflow' => $workflow,
            'retry_count' => 90,
            'max_retries' => 90,
            'category' => 'timeout',
        ],
    ]);

    expect(app(RetryFailedPost::class)->execute($failedInstagram->fresh()))->toBe(RetryOutcome::Queued);

    expect($failedInstagram->fresh()->publish_status)->toBe(PlatformStatus::Pending)
        ->and($failedInstagram->fresh()->error_context)->toEqual([
            'instagram_workflow' => $workflow,
        ]);
});

test('it removes stale TikTok derivatives when there is no publish_id to resume', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Storage::fake();

    $derivativePath = 'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::put($derivativePath, 'temporary image');
    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $failedTikTok = Post::factory()->tiktok()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'error_context' => [
            'tiktok_derivative_paths' => [$derivativePath],
            'category' => 'unknown',
        ],
    ]);

    expect(app(RetryFailedPost::class)->execute($failedTikTok->fresh()))->toBe(RetryOutcome::Queued);

    Storage::assertMissing($derivativePath);
    expect($failedTikTok->fresh()->publish_status)->toBe(PlatformStatus::Pending)
        ->and($failedTikTok->fresh()->error_context)->toBeNull();

    Bus::assertDispatched(PublishToSocialPlatform::class, fn (PublishToSocialPlatform $job): bool => $job->post->is($failedTikTok));
});

test('it rejects posts that are not in a terminal failure state', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    $post = Post::factory()->linkedin()->publishing()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    expect(app(RetryFailedPost::class)->execute($post->fresh()))->toBe(RetryOutcome::NotRetryable);

    Bus::assertNotDispatched(PublishToSocialPlatform::class);
});

test('it queues nothing when the post changed after it was read', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $post = Post::factory()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'error_context' => ['category' => 'rate_limit'],
    ]);
    $stale = $post->fresh();
    Post::query()->whereKey($post->id)->toBase()->update(['error_context' => json_encode(['category' => 'server_error'])]);

    expect(app(RetryFailedPost::class)->execute($stale))->toBe(RetryOutcome::Changed)
        ->and($post->fresh()->status)->toBe(PostStatus::Failed)
        ->and($post->fresh()->error_context)->toEqual(['category' => 'server_error']);
    Bus::assertNotDispatched(PublishToSocialPlatform::class);
});

test('it retries a completely failed post', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $failedPlatform = Post::factory()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
    ]);

    expect(app(RetryFailedPost::class)->execute($failedPlatform->fresh()))->toBe(RetryOutcome::Queued);

    expect($failedPlatform->fresh()->status)->toBe(PostStatus::Publishing)
        ->and($failedPlatform->fresh()->publish_status)->toBe(PlatformStatus::Pending);

    Bus::assertDispatched(PublishToSocialPlatform::class, fn (PublishToSocialPlatform $job): bool => $job->post->is($failedPlatform));
});

test('it rejects a failed post without a channel', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    $post = Post::factory()->failed()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    expect(app(RetryFailedPost::class)->execute($post->fresh()))->toBe(RetryOutcome::NotRetryable);

    expect($post->fresh()->status)->toBe(PostStatus::Failed);
    Bus::assertNotDispatched(PublishToSocialPlatform::class);
});

test('a TikTok retry with a publish_id resumes instead of calling init', function () {
    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'tiktoker',
        'token_expires_at' => now()->addDay(),
    ]);
    $failedTikTok = Post::factory()->tiktok()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'media' => [[
            'id' => 'test-media-video',
            'path' => 'media/2026-01/test-video.mp4',
            'url' => 'https://example.com/media/2026-01/test-video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'test-video.mp4',
        ]],
        'error_context' => [
            'tiktok_publish_id' => 'pub_existing',
            'retry_count' => 120,
            'max_retries' => 120,
            'category' => 'platform_unavailable',
        ],
    ]);

    Mail::fake();
    Queue::fake();

    expect(app(RetryFailedPost::class)->execute($failedTikTok->fresh()))->toBe(RetryOutcome::Queued);

    $api = config('trypost.platforms.tiktok.api');
    Http::fake([
        $api.'/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'PUBLISH_COMPLETE',
                'publicaly_available_post_id' => ['7000000000000000123'],
            ],
        ]),
    ]);

    (new PublishToSocialPlatform($failedTikTok->fresh()))->handle();

    expect($failedTikTok->fresh()->publish_status)->toBe(PlatformStatus::Published)
        ->and($failedTikTok->fresh()->platform_post_id)->toBe('7000000000000000123');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/init/'));
});

test('an Instagram retry with a workflow resumes instead of creating a container', function () {
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'ig_123456789',
        'token_expires_at' => now()->addDays(60),
    ]);
    $failedInstagram = Post::factory()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
        'content_type' => ContentType::InstagramFeed,
        'error_context' => [
            'instagram_workflow' => [
                'stage' => 'final_container',
                'container_id' => 'container-123',
            ],
            'retry_count' => 90,
            'max_retries' => 90,
            'category' => 'platform_unavailable',
        ],
    ]);

    Mail::fake();
    Queue::fake();

    expect(app(RetryFailedPost::class)->execute($failedInstagram->fresh()))->toBe(RetryOutcome::Queued);

    Http::fake([
        'https://graph.instagram.com/v25.0/container-123*' => Http::response(['status_code' => 'FINISHED'], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response(['id' => 'media-123456789'], 200),
        'https://graph.instagram.com/v25.0/media-123456789*' => Http::response([
            'permalink' => 'https://www.instagram.com/p/ABC123/',
        ], 200),
    ]);

    (new PublishToSocialPlatform($failedInstagram->fresh()))->handle();

    expect($failedInstagram->fresh()->publish_status)->toBe(PlatformStatus::Published)
        ->and($failedInstagram->fresh()->platform_post_id)->toBe('media-123456789');

    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/ig_123456789/media'));
});

test('it treats an empty TikTok publish_id as a new attempt', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Storage::fake();

    $derivativePath = 'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::put($derivativePath, 'temporary image');
    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $failedTikTok = Post::factory()->tiktok()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'error_context' => [
            'tiktok_publish_id' => '',
            'tiktok_derivative_paths' => [$derivativePath],
            'category' => 'unknown',
        ],
    ]);

    expect(app(RetryFailedPost::class)->execute($failedTikTok->fresh()))->toBe(RetryOutcome::Queued);

    Storage::assertMissing($derivativePath);
    expect($failedTikTok->fresh()->publish_status)->toBe(PlatformStatus::Pending)
        ->and($failedTikTok->fresh()->error_context)->toBeNull();
});

test('it treats an empty Instagram workflow as a new attempt', function () {
    Bus::fake([PublishToSocialPlatform::class]);

    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $failedInstagram = Post::factory()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'error_context' => [
            'instagram_workflow' => [],
            'category' => 'unknown',
        ],
    ]);

    expect(app(RetryFailedPost::class)->execute($failedInstagram->fresh()))->toBe(RetryOutcome::Queued);

    expect($failedInstagram->fresh()->publish_status)->toBe(PlatformStatus::Pending)
        ->and($failedInstagram->fresh()->error_context)->toBeNull();
});

test('it starts over when the failure category is not resumable', function (?string $category) {
    Bus::fake([PublishToSocialPlatform::class]);

    $errorContext = ['tiktok_publish_id' => 'pub_dead'];

    if ($category !== null) {
        $errorContext['category'] = $category;
    }

    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $failedTikTok = Post::factory()->tiktok()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'error_context' => $errorContext,
    ]);

    expect(app(RetryFailedPost::class)->execute($failedTikTok->fresh()))->toBe(RetryOutcome::Queued);

    expect($failedTikTok->fresh()->publish_status)->toBe(PlatformStatus::Pending)
        ->and($failedTikTok->fresh()->error_context)->toBeNull();
})->with([
    'media format' => ['media_format'],
    'content policy' => ['content_policy'],
    'server error' => ['server_error'],
    'permission' => ['permission'],
    'rate limit' => ['rate_limit'],
    'unknown' => ['unknown'],
    'missing category' => [null],
]);

test('a TikTok retry after a remote FAILED starts a new publish', function () {
    Sleep::fake();

    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'tiktoker',
        'token_expires_at' => now()->addDay(),
    ]);
    $failedTikTok = Post::factory()->tiktok()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'media' => [[
            'id' => 'test-media-video',
            'path' => 'media/2026-01/test-video.mp4',
            'url' => 'https://example.com/media/2026-01/test-video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'test-video.mp4',
        ]],
        'error_context' => [
            'tiktok_publish_id' => 'pub_dead',
            'category' => 'media_format',
        ],
    ]);

    Mail::fake();
    Queue::fake();

    expect(app(RetryFailedPost::class)->execute($failedTikTok->fresh()))->toBe(RetryOutcome::Queued);

    expect($failedTikTok->fresh()->error_context)->toBeNull();

    $api = config('trypost.platforms.tiktok.api');
    Http::fake([
        $api.'/post/publish/video/init/' => Http::response([
            'data' => ['publish_id' => 'pub_fresh'],
        ], 200),
        $api.'/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'PUBLISH_COMPLETE',
                'publicaly_available_post_id' => ['7000000000000000456'],
            ],
        ]),
    ]);

    (new PublishToSocialPlatform($failedTikTok->fresh()))->handle();

    expect($failedTikTok->fresh()->publish_status)->toBe(PlatformStatus::Published)
        ->and($failedTikTok->fresh()->platform_post_id)->toBe('7000000000000000456');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/init/'));
});

test('an Instagram retry after a container ERROR starts a new container', function () {
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'ig_123456789',
        'token_expires_at' => now()->addDays(60),
    ]);
    $failedInstagram = Post::factory()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
        'content_type' => ContentType::InstagramFeed,
        'error_context' => [
            'instagram_workflow' => [
                'stage' => 'final_container',
                'container_id' => 'container-dead',
            ],
            'category' => 'server_error',
        ],
    ]);

    Mail::fake();
    Queue::fake();

    expect(app(RetryFailedPost::class)->execute($failedInstagram->fresh()))->toBe(RetryOutcome::Queued);

    expect($failedInstagram->fresh()->error_context)->toBeNull();

    Http::fake([
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['id' => 'container-fresh'], 200),
        'https://graph.instagram.com/v25.0/container-fresh*' => Http::response(['status_code' => 'FINISHED'], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response(['id' => 'media-456'], 200),
        'https://graph.instagram.com/v25.0/media-456*' => Http::response([
            'permalink' => 'https://www.instagram.com/p/DEF456/',
        ], 200),
    ]);

    (new PublishToSocialPlatform($failedInstagram->fresh()))->handle();

    expect($failedInstagram->fresh()->publish_status)->toBe(PlatformStatus::Published)
        ->and($failedInstagram->fresh()->platform_post_id)->toBe('media-456');

    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/ig_123456789/media'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'container-dead'));
});

test('an Instagram retry after a container EXPIRED starts a new container', function () {
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'ig_123456789',
        'token_expires_at' => now()->addDays(60),
    ]);
    $failedInstagram = Post::factory()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
        'content_type' => ContentType::InstagramFeed,
        'error_context' => [
            'instagram_workflow' => [
                'stage' => 'final_container',
                'container_id' => 'container-expired',
            ],
            'category' => 'platform_unavailable',
        ],
    ]);

    Mail::fake();
    Queue::fake();

    expect(app(RetryFailedPost::class)->execute($failedInstagram->fresh()))->toBe(RetryOutcome::Queued);

    Http::fake([
        'https://graph.instagram.com/v25.0/container-expired*' => Http::response(['status_code' => 'EXPIRED'], 200),
    ]);

    (new PublishToSocialPlatform($failedInstagram->fresh()))->handle();

    expect($failedInstagram->fresh()->publish_status)->toBe(PlatformStatus::Failed)
        ->and($failedInstagram->fresh()->error_context['category'] ?? null)->toBe('server_error');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/media_publish'));

    expect(app(RetryFailedPost::class)->execute($failedInstagram->fresh()))->toBe(RetryOutcome::Queued);

    expect($failedInstagram->fresh()->error_context)->toBeNull();

    Http::fake([
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['id' => 'container-fresh'], 200),
        'https://graph.instagram.com/v25.0/container-fresh*' => Http::response(['status_code' => 'FINISHED'], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response(['id' => 'media-789'], 200),
        'https://graph.instagram.com/v25.0/media-789*' => Http::response([
            'permalink' => 'https://www.instagram.com/p/GHI789/',
        ], 200),
    ]);

    (new PublishToSocialPlatform($failedInstagram->fresh()))->handle();

    expect($failedInstagram->fresh()->publish_status)->toBe(PlatformStatus::Published)
        ->and($failedInstagram->fresh()->platform_post_id)->toBe('media-789');

    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/ig_123456789/media'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'container-expired'));
});

test('an Instagram resume of a published container completes without media_publish', function () {
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'ig_123456789',
        'token_expires_at' => now()->addDays(60),
    ]);
    $failedInstagram = Post::factory()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'content_type' => ContentType::InstagramFeed,
        'error_context' => [
            'instagram_workflow' => [
                'stage' => 'final_container',
                'container_id' => 'container-123',
            ],
            'category' => 'platform_unavailable',
        ],
    ]);

    Mail::fake();
    Queue::fake();

    expect(app(RetryFailedPost::class)->execute($failedInstagram->fresh()))->toBe(RetryOutcome::Queued);

    Http::fake(function (Request $request) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/container-123')) {
            return Http::response(['status_code' => 'PUBLISHED'], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/ig_123456789/media')) {
            return Http::response([
                'data' => [[
                    'id' => 'other-account-post',
                    'permalink' => 'https://www.instagram.com/p/WRONG/',
                ]],
            ], 200);
        }

        return Http::response(['error' => ['message' => 'unexpected']], 500);
    });

    (new PublishToSocialPlatform($failedInstagram->fresh()))->handle();

    expect($failedInstagram->fresh()->publish_status)->toBe(PlatformStatus::Published)
        ->and($failedInstagram->fresh()->platform_post_id)->toBe('container-123')
        ->and($failedInstagram->fresh()->platform_url)->toBeNull();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ig_123456789/media'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/media_publish'));
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('an Instagram resume of a checkpointed media id completes without media_publish', function () {
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'ig_123456789',
        'token_expires_at' => now()->addDays(60),
    ]);
    $failedInstagram = Post::factory()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'content_type' => ContentType::InstagramFeed,
        'error_context' => [
            'instagram_workflow' => [
                'stage' => 'final_container',
                'container_id' => 'container-123',
                'media_id' => 'media-persisted',
            ],
            'category' => 'job_failed',
        ],
    ]);

    Mail::fake();
    Queue::fake();

    expect(app(RetryFailedPost::class)->execute($failedInstagram->fresh()))->toBe(RetryOutcome::Queued);

    Http::fake(function (Request $request) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/media-persisted')) {
            return Http::response([
                'permalink' => 'https://www.instagram.com/p/PERSISTED/',
            ], 200);
        }

        return Http::response(['error' => ['message' => 'unexpected']], 500);
    });

    (new PublishToSocialPlatform($failedInstagram->fresh()))->handle();

    expect($failedInstagram->fresh()->publish_status)->toBe(PlatformStatus::Published)
        ->and($failedInstagram->fresh()->platform_post_id)->toBe('media-persisted')
        ->and($failedInstagram->fresh()->platform_url)->toBe('https://www.instagram.com/p/PERSISTED/');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/container-123'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/media_publish'));
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('it keeps the live segments of a thread whatever the failure category', function (?string $category) {
    Bus::fake([PublishToSocialPlatform::class]);

    $progress = [['hash' => 'h0', 'id' => '1'], ['hash' => 'h1', 'id' => '2']];
    $account = SocialAccount::factory()->mastodon()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $failedMastodon = Post::factory()->forAccount($account)->failed()->create([
        'user_id' => $this->user->id,
        'error_context' => array_filter(['category' => $category, 'thread_progress' => $progress, 'failed_at' => now()->toIso8601String()]),
    ]);

    expect(app(RetryFailedPost::class)->execute($failedMastodon->fresh()))->toBe(RetryOutcome::Queued);

    expect($failedMastodon->fresh()->publish_status)->toBe(PlatformStatus::Pending)
        ->and($failedMastodon->fresh()->error_context)->toEqual(['thread_progress' => $progress]);
})->with([
    'media format' => ['media_format'],
    'timeout' => ['timeout'],
    'missing category' => [null],
]);
