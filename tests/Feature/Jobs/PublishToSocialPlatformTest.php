<?php

declare(strict_types=1);

use App\Enums\Notification\Type;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Enums\UserWorkspace\Role;
use App\Events\PostPlatformStatusUpdated;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\LinkedInPublishException;
use App\Exceptions\TokenExpiredException;
use App\Jobs\PublishToSocialPlatform;
use App\Jobs\SendNotification;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\LinkedInPagePublisher;
use App\Services\Social\LinkedInPublisher;
use App\Services\Social\PinterestPublisher;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Mail::fake();
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->socialAccount = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $this->post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $this->postPlatform = PostPlatform::factory()->linkedin()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);
});

test('publish to social platform marks platform as publishing', function () {
    Event::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andReturn([
        'id' => 'post-123',
        'url' => 'https://linkedin.com/post/123',
    ]);

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    Event::assertDispatched(PostPlatformStatusUpdated::class);
});

test('publish to social platform marks platform as published on success', function () {
    Event::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andReturn([
        'id' => 'post-123',
        'url' => 'https://linkedin.com/post/123',
    ]);

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    expect($this->postPlatform->status)->toBe(PlatformStatus::Published);
    expect($this->postPlatform->platform_post_id)->toBe('post-123');
    expect($this->postPlatform->platform_url)->toBe('https://linkedin.com/post/123');
});

test('publish job dispatches a linkedin-page post to the page publisher', function () {
    Event::fake();

    $workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $pageAccount = SocialAccount::factory()->linkedinPage()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $this->user->id,
    ]);
    $pagePostPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $pageAccount->id,
        'platform' => Platform::LinkedInPage,
        'content_type' => ContentType::LinkedInPagePost,
        'enabled' => true,
    ]);

    $publisher = Mockery::mock(LinkedInPagePublisher::class);
    $publisher->shouldReceive('publish')->once()->andReturn([
        'id' => 'org-post-1',
        'url' => 'https://linkedin.com/company/post/1',
    ]);
    $this->app->instance(LinkedInPagePublisher::class, $publisher);

    (new PublishToSocialPlatform($pagePostPlatform))->handle();

    $pagePostPlatform->refresh();
    expect($pagePostPlatform->status)->toBe(PlatformStatus::Published);
    expect($pagePostPlatform->platform_post_id)->toBe('org-post-1');
});

test('publish job runs the real document flow end-to-end for a LinkedIn PDF post', function () {
    Event::fake();

    // Real publisher (no mock) — exercise the full job -> getPublisher -> publishDocument chain.
    $this->socialAccount->update([
        'platform_user_id' => 'person-xyz',
        'token_expires_at' => now()->addDays(60),
    ]);
    $this->postPlatform->update(['content_type' => ContentType::LinkedInPost]);
    $this->post->update([
        'content' => 'Our deck',
        'media' => [[
            'id' => 'doc-1', 'path' => 'media/deck.pdf', 'url' => 'https://example.com/deck.pdf',
            'type' => 'document', 'mime_type' => 'application/pdf', 'original_filename' => 'deck.pdf',
        ]],
    ]);

    $uploadUrl = 'https://www.linkedin.com/dms-uploads/document/e2e';

    Http::fake(function ($request) use ($uploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/rest/documents') && str_contains($url, 'initializeUpload')) {
            return Http::response(['value' => ['uploadUrl' => $uploadUrl, 'document' => 'urn:li:document:E2E']], 200);
        }

        if ($url === $uploadUrl) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/documents/')) {
            return Http::response(['status' => 'AVAILABLE'], 200);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:e2e']);
        }

        return Http::response('fake-pdf-bytes', 200);
    });

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    expect($this->postPlatform->status)->toBe(PlatformStatus::Published);
    expect($this->postPlatform->platform_post_id)->toBe('urn:li:share:e2e');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/rest/documents') && str_contains($request->url(), 'initializeUpload'));
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/rest/posts')
            && data_get($request->data(), 'content.media.id') === 'urn:li:document:E2E'
            && data_get($request->data(), 'content.media.title') === 'deck.pdf';
    });
});

test('publish to social platform marks platform as failed on error', function () {
    Event::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new Exception('API Error'));

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    expect($this->postPlatform->status)->toBe(PlatformStatus::Failed);
    expect($this->postPlatform->error_message)->toBe('An unexpected error occurred while publishing. Please try again.');
});

test('publish keeps the vetted user message from a publish exception', function () {
    Event::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new LinkedInPublishException(
        userMessage: 'LinkedIn rejected this post.',
        category: ErrorCategory::ContentPolicy,
    ));

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    expect($this->postPlatform->status)->toBe(PlatformStatus::Failed);
    expect($this->postPlatform->error_message)->toBe('LinkedIn rejected this post.');
});

test('publish never leaks a raw internal error to the failure record (and the email)', function () {
    Event::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new TypeError(
        'X::getMediaCategory(): Argument #1 ($mimeType) must be of type string, null given, called in /home/forge/app.trypost.it/releases/72198060/app/Services/Social/XPublisher.php on line 130'
    ));

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    expect($this->postPlatform->status)->toBe(PlatformStatus::Failed);
    expect($this->postPlatform->error_message)->toBe('An unexpected error occurred while publishing. Please try again.')
        ->and($this->postPlatform->error_message)->not->toContain('/home/forge')
        ->and($this->postPlatform->error_message)->not->toContain('getMediaCategory');
});

test('the job-failed hook also genericizes a raw internal error', function () {
    Event::fake();

    (new PublishToSocialPlatform($this->postPlatform))->failed(new TypeError(
        'boom in /home/forge/app.trypost.it/releases/72198060/app/Services/Social/XPublisher.php on line 130'
    ));

    $this->postPlatform->refresh();
    expect($this->postPlatform->status)->toBe(PlatformStatus::Failed)
        ->and($this->postPlatform->error_message)->toBe('An unexpected error occurred while publishing. Please try again.')
        ->and($this->postPlatform->error_message)->not->toContain('/home/forge');
});

test('publish to social platform marks account as token expired on auth failure', function () {
    Event::fake();
    Mail::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new TokenExpiredException('Token expired', '401'));

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    $this->socialAccount->refresh();

    expect($this->postPlatform->status)->toBe(PlatformStatus::Failed);
    expect($this->socialAccount->status)->toBe(AccountStatus::TokenExpired);
});

test('publish reschedules platform unavailable retry via Bus dispatch (not marked Failed, not expired)', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException('LinkedIn API returned 503 during token refresh', 503)
    );

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    $this->socialAccount->refresh();

    expect($this->postPlatform->status)->toBe(PlatformStatus::Retrying);
    expect($this->postPlatform->error_context['category'] ?? null)->toBe('platform_unavailable');
    expect($this->postPlatform->error_context['http_status'] ?? null)->toBe(503);
    expect($this->postPlatform->error_context['retry_count'] ?? null)->toBe(1);
    expect($this->postPlatform->error_message)->toBe(__('posts.errors.platform_unavailable'));
    expect($this->postPlatform->error_context['detail'] ?? null)->toContain('LinkedIn API returned 503');
    expect($this->socialAccount->status)->toBe(AccountStatus::Connected);

    Bus::assertDispatched(PublishToSocialPlatform::class, function ($job) {
        return $job->postPlatform->id === $this->postPlatform->id
            && $job->uniqueAttempt === 1
            && $job->uniqueId() === "{$this->postPlatform->id}:1";
    });
});

test('publish reschedules when pinterest video processing times out', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();

    $pinterestAccount = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
        'meta' => ['default_board_id' => 'board_123'],
    ]);

    $postPlatform = PostPlatform::factory()->pinterest()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $pinterestAccount->id,
        'platform' => Platform::Pinterest,
        'content_type' => ContentType::PinterestVideoPin,
        'enabled' => true,
        'status' => PlatformStatus::Pending,
        'meta' => ['board_id' => 'board_123'],
    ]);

    $publisher = Mockery::mock(PinterestPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException(
            'Pinterest media processing timeout after 60 attempts (media_id=media_abc, last_status=processing)'
        )
    );
    $this->app->instance(PinterestPublisher::class, $publisher);

    (new PublishToSocialPlatform($postPlatform))->handle();

    $postPlatform->refresh();

    expect($postPlatform->status)->toBe(PlatformStatus::Retrying)
        ->and($postPlatform->error_context['category'] ?? null)->toBe('platform_unavailable')
        ->and($postPlatform->error_message)->toBe(__('posts.errors.platform_unavailable'))
        ->and($postPlatform->error_context['detail'] ?? null)->toContain('Pinterest media processing timeout');

    Bus::assertDispatched(PublishToSocialPlatform::class, function ($job) use ($postPlatform) {
        return $job->postPlatform->id === $postPlatform->id
            && $job->uniqueAttempt === 1;
    });
});

test('publish reschedules retry when retry-refresh path hits platform unavailable', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();

    // Publisher first throws TokenExpired (401-style), the retry-refresh
    // path goes through ConnectionVerifier::verify which can in turn raise
    // PlatformUnavailable if the platform is down.
    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new TokenExpiredException('Token expired', '401'));

    $verifier = Mockery::mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->andThrow(
        new PlatformUnavailableException('LinkedIn API returned 503 during token refresh', 503)
    );

    $this->app->instance(LinkedInPublisher::class, $publisher);
    $this->app->instance(ConnectionVerifier::class, $verifier);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    $this->socialAccount->refresh();

    expect($this->postPlatform->status)->toBe(PlatformStatus::Retrying);
    expect($this->postPlatform->error_context['category'] ?? null)->toBe('platform_unavailable');
    expect($this->socialAccount->status)->toBe(AccountStatus::Connected);

    Bus::assertDispatched(PublishToSocialPlatform::class);
});

test('publish reschedules retry exactly 10 minutes into the future', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();

    $now = now()->startOfMinute();
    Carbon::setTestNow($now);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException('LinkedIn 503', 503)
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();

    // error_context tracks the next attempt — must be exactly +10 min
    expect($this->postPlatform->error_context['next_attempt_at'] ?? null)
        ->toBe($now->copy()->addMinutes(10)->toIso8601String());

    // The actual dispatched job carries the same delay
    Bus::assertDispatched(PublishToSocialPlatform::class, function ($job) use ($now) {
        // $job->delay is a Carbon|DateInterval|int set by ->delay(...)
        $delayAt = $job->delay instanceof DateTimeInterface
            ? Carbon::instance($job->delay)
            : null;

        return $delayAt !== null
            && $delayAt->equalTo($now->copy()->addMinutes(10));
    });

    Carbon::setTestNow();
});

test('publish records last_attempt_at when rescheduling for retry', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();

    $now = now()->startOfMinute();
    Carbon::setTestNow($now);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException('LinkedIn 503', 503)
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();

    expect($this->postPlatform->error_context['last_attempt_at'] ?? null)
        ->toBe($now->toIso8601String());

    Carbon::setTestNow();
});

test('post stays in Publishing while one platform is still Retrying', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();

    // Second LinkedIn account on the same post — first one will publish OK,
    // second one will hit PlatformUnavailable and reschedule.
    $secondAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $secondPlatform = PostPlatform::factory()->linkedin()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $secondAccount->id,
        'enabled' => true,
        'status' => PlatformStatus::Published,  // simulate already published
        'platform_post_id' => 'sibling-123',
    ]);

    // Start the post as Publishing so updatePostStatus sees the in-flight context
    $this->post->update(['status' => PostStatus::Publishing]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException('LinkedIn 503', 503)
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    $this->post->refresh();

    expect($this->postPlatform->status)->toBe(PlatformStatus::Retrying);
    // Post is NOT finalized because one of its platforms is still pending retry.
    expect($this->post->status)->toBe(PostStatus::Publishing);
});

test('successful publish after a retry transitions the platform to Published', function () {
    // Pre-condition: this platform already failed once and is currently Retrying.
    $this->postPlatform->update([
        'status' => PlatformStatus::Retrying,
        'error_context' => ['retry_count' => 3, 'category' => 'platform_unavailable'],
    ]);

    Event::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andReturn([
        'id' => 'post-after-retry',
        'url' => 'https://linkedin.com/post/after-retry',
    ]);
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();

    expect($this->postPlatform->status)->toBe(PlatformStatus::Published);
    expect($this->postPlatform->platform_post_id)->toBe('post-after-retry');
});

test('publish retry count increments across successive platform_unavailable attempts', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException('LinkedIn 503', 503)
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    // Simulate prior attempts
    $this->postPlatform->update([
        'error_context' => ['retry_count' => 5],
    ]);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();

    expect($this->postPlatform->status)->toBe(PlatformStatus::Retrying);
    expect($this->postPlatform->error_context['retry_count'] ?? null)->toBe(6);
});

test('publish hard-fails when platform unavailable retries are exhausted', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException('LinkedIn 503', 503)
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    $this->postPlatform->update([
        'error_context' => ['retry_count' => PublishToSocialPlatform::MAX_PLATFORM_UNAVAILABLE_RETRIES],
    ]);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();

    expect($this->postPlatform->status)->toBe(PlatformStatus::Failed)
        ->and($this->postPlatform->error_message)->toBe(__('posts.errors.platform_unavailable_exhausted'))
        ->and($this->postPlatform->error_context['category'] ?? null)->toBe('platform_unavailable')
        ->and($this->postPlatform->error_context['retry_count'] ?? null)->toBe(PublishToSocialPlatform::MAX_PLATFORM_UNAVAILABLE_RETRIES + 1)
        ->and($this->postPlatform->error_context['detail'] ?? null)->toContain('LinkedIn 503');

    Bus::assertNotDispatched(PublishToSocialPlatform::class);
});

test('publish skips platforms that are already failed', function () {
    Event::fake();
    Mail::fake();

    $this->postPlatform->update([
        'status' => PlatformStatus::Failed,
        'error_message' => __('posts.errors.publishing_timed_out'),
    ]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldNotReceive('publish');
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();

    expect($this->postPlatform->status)->toBe(PlatformStatus::Failed)
        ->and($this->postPlatform->error_message)->toBe(__('posts.errors.publishing_timed_out'));
});

test('publish job timeout leaves headroom above the pinterest media poll budget', function () {
    $job = new PublishToSocialPlatform($this->postPlatform);
    $horizonTimeout = (int) config('horizon.defaults.social-publishing.timeout');
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    expect($job->timeout)->toBe(900)
        ->and($horizonTimeout)->toBe(930)
        ->and($retryAfter)->toBe(960)
        ->and($job->uniqueFor)->toBe(960)
        ->and($job->timeout)->toBeLessThan($horizonTimeout)
        ->and($horizonTimeout)->toBeLessThan($retryAfter)
        ->and($job->uniqueFor)->toBeGreaterThanOrEqual($job->timeout);
});

test('publish job unique id includes the platform and attempt', function () {
    $job = new PublishToSocialPlatform($this->postPlatform, 3);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe("{$this->postPlatform->id}:3")
        ->and($job->uniqueFor)->toBe(960);
});

test('publish job unique lock drops a duplicate dispatch for the same platform attempt', function () {
    Bus::fake([PublishToSocialPlatform::class]);

    PublishToSocialPlatform::dispatch($this->postPlatform, 0);
    PublishToSocialPlatform::dispatch($this->postPlatform, 0);

    Bus::assertDispatchedTimes(PublishToSocialPlatform::class, 1);
});

test('publish job unique lock allows concurrent dispatches for different attempts', function () {
    Bus::fake([PublishToSocialPlatform::class]);

    PublishToSocialPlatform::dispatch($this->postPlatform, 0);
    PublishToSocialPlatform::dispatch($this->postPlatform, 1);

    Bus::assertDispatchedTimes(PublishToSocialPlatform::class, 2);
    Bus::assertDispatched(PublishToSocialPlatform::class, fn ($job) => $job->uniqueAttempt === 0);
    Bus::assertDispatched(PublishToSocialPlatform::class, fn ($job) => $job->uniqueAttempt === 1);
});

test('failed hook skips platforms that are already failed', function () {
    Event::fake();
    Mail::fake();

    $this->postPlatform->update([
        'status' => PlatformStatus::Failed,
        'error_message' => __('posts.errors.publishing_timed_out'),
        'error_context' => ['category' => 'timeout'],
    ]);

    (new PublishToSocialPlatform($this->postPlatform))->failed(new TypeError('Simulated worker kill'));

    $this->postPlatform->refresh();

    expect($this->postPlatform->status)->toBe(PlatformStatus::Failed)
        ->and($this->postPlatform->error_message)->toBe(__('posts.errors.publishing_timed_out'))
        ->and($this->postPlatform->error_context['category'] ?? null)->toBe('timeout');
});

test('failed hook skips platforms that are already published', function () {
    Event::fake();
    Mail::fake();

    $this->postPlatform->update([
        'status' => PlatformStatus::Published,
        'platform_post_id' => 'already-published',
        'error_message' => null,
    ]);

    (new PublishToSocialPlatform($this->postPlatform))->failed(new TypeError('Simulated worker kill'));

    $this->postPlatform->refresh();

    expect($this->postPlatform->status)->toBe(PlatformStatus::Published)
        ->and($this->postPlatform->platform_post_id)->toBe('already-published')
        ->and($this->postPlatform->error_message)->toBeNull();
});

test('pinterest media status 401 marks the account token expired and notifies to reconnect', function () {
    Event::fake();
    Queue::fake();
    Mail::fake();
    Sleep::fake();

    $pinterestAccount = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
        'status' => AccountStatus::Connected,
        'token_expires_at' => now()->addDays(30),
        'meta' => ['default_board_id' => 'board_123'],
    ]);

    $postPlatform = PostPlatform::factory()->pinterest()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $pinterestAccount->id,
        'platform' => Platform::Pinterest,
        'content_type' => ContentType::PinterestVideoPin,
        'enabled' => true,
        'status' => PlatformStatus::Pending,
        'meta' => ['board_id' => 'board_123'],
    ]);

    $this->post->update([
        'media' => [[
            'id' => 'test-media-video',
            'path' => 'media/2026-01/video.mp4',
            'url' => 'https://example.com/media/2026-01/video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'video.mp4',
        ]],
    ]);

    $s3UploadUrl = 'https://pinterest-media-upload.s3.amazonaws.com/upload';

    Http::fake(function ($request) use ($s3UploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/v5/media') && $request->method() === 'POST') {
            return Http::response([
                'media_id' => 'media_video_401_job',
                'upload_url' => $s3UploadUrl,
                'upload_parameters' => [],
            ], 201);
        }

        if ($url === $s3UploadUrl) {
            return Http::response('', 204);
        }

        if (str_contains($url, '/v5/media/media_video_401_job')) {
            return Http::response(['message' => 'Access token has expired or been revoked'], 401);
        }

        return Http::response('fake-video-content', 200);
    });

    $verifier = Mockery::mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new Exception('Refresh failed'));
    $this->app->instance(ConnectionVerifier::class, $verifier);

    (new PublishToSocialPlatform($postPlatform))->handle();

    $postPlatform->refresh();
    $pinterestAccount->refresh();

    expect($postPlatform->status)->toBe(PlatformStatus::Failed)
        ->and($postPlatform->error_context['category'] ?? null)->toBe('token_expired')
        ->and($pinterestAccount->status)->toBe(AccountStatus::TokenExpired);

    Queue::assertPushed(SendNotification::class, function ($job) use ($pinterestAccount) {
        return $job->type === Type::AccountDisconnected
            && data_get($job->data, 'social_account_id') === $pinterestAccount->id
            && str_contains($job->title, 'needs to be reconnected');
    });
});

test('publish to social platform updates post status when all platforms finished', function () {
    Event::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andReturn([
        'id' => 'post-123',
        'url' => 'https://linkedin.com/post/123',
    ]);

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->post->refresh();
    expect($this->post->status)->toBe(PostStatus::Published);
});

test('publish to social platform marks post as partially published when some fail', function () {
    Event::fake();

    $socialAccount2 = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $postPlatform2 = PostPlatform::factory()->x()->failed()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $socialAccount2->id,
        'enabled' => true,
    ]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andReturn([
        'id' => 'post-123',
        'url' => 'https://linkedin.com/post/123',
    ]);

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->post->refresh();
    expect($this->post->status)->toBe(PostStatus::PartiallyPublished);
});

test('publish to social platform marks post as failed when all platforms fail', function () {
    Event::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new Exception('API Error'));

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->post->refresh();
    expect($this->post->status)->toBe(PostStatus::Failed);
});

test('publish to social platform skips publishing when account is disconnected', function () {
    Event::fake();

    $this->socialAccount->update([
        'status' => AccountStatus::Disconnected,
        'disconnected_at' => now(),
    ]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldNotReceive('publish');

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    expect($this->postPlatform->status)->toBe(PlatformStatus::Failed);
    expect($this->postPlatform->error_message)->toBe(__('posts.errors.account_disconnected'));
});

test('publish to social platform skips publishing when account token is expired', function () {
    Event::fake();

    $this->socialAccount->update([
        'status' => AccountStatus::TokenExpired,
        'disconnected_at' => now(),
    ]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldNotReceive('publish');

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    expect($this->postPlatform->status)->toBe(PlatformStatus::Failed);
    expect($this->postPlatform->error_message)->toBe(__('posts.errors.account_token_expired'));
    expect($this->postPlatform->error_context['category'])->toBe('token_expired');
});

test('publish to social platform skips publishing when account is inactive', function () {
    Event::fake();

    $this->socialAccount->update(['is_active' => false]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldNotReceive('publish');

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    expect($this->postPlatform->status)->toBe(PlatformStatus::Failed);
    expect($this->postPlatform->error_message)->toBe(__('posts.errors.account_inactive'));
});

test('publish to social platform dispatches success notification when all platforms published', function () {
    Event::fake();
    Queue::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andReturn([
        'id' => 'post-123',
        'url' => 'https://linkedin.com/post/123',
    ]);

    $this->app->instance(LinkedInPublisher::class, $publisher);

    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->post->refresh();
    expect($this->post->status)->toBe(PostStatus::Published);
    Queue::assertPushed(SendNotification::class);
});

test('publish to social platform dispatches failure notification when platform fails', function () {
    Event::fake();
    Queue::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new Exception('API error'));

    $this->app->instance(LinkedInPublisher::class, $publisher);

    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->post->refresh();
    expect($this->post->status)->toBe(PostStatus::Failed);
    Queue::assertPushed(SendNotification::class);
});

test('it retries with token refresh when token expires during publish', function () {
    Event::fake();

    $callCount = 0;
    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')
        ->twice()
        ->andReturnUsing(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new TokenExpiredException('Token expired', '401');
            }

            return ['id' => 'post-123', 'url' => 'https://linkedin.com/post/123'];
        });

    $this->app->instance(LinkedInPublisher::class, $publisher);

    $verifier = Mockery::mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andReturn(true);

    $this->app->instance(ConnectionVerifier::class, $verifier);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    $this->socialAccount->refresh();

    expect($this->postPlatform->status)->toBe(PlatformStatus::Published);
    expect($this->socialAccount->status)->not->toBe(AccountStatus::Disconnected);
});

test('it marks account as token expired when refresh fails during publish retry', function () {
    Event::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')
        ->once()
        ->andThrow(new TokenExpiredException('Token expired', '401'));

    $this->app->instance(LinkedInPublisher::class, $publisher);

    $verifier = Mockery::mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new Exception('Refresh failed'));

    $this->app->instance(ConnectionVerifier::class, $verifier);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    $this->socialAccount->refresh();

    expect($this->postPlatform->status)->toBe(PlatformStatus::Failed);
    expect($this->socialAccount->status)->toBe(AccountStatus::TokenExpired);
});

test('publish to social platform skips if already published (idempotency)', function () {
    Event::fake();

    // Mark as already published
    $this->postPlatform->update([
        'status' => PlatformStatus::Published,
        'platform_post_id' => 'existing-123',
    ]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldNotReceive('publish');

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    // Should not have called publish
    $this->postPlatform->refresh();
    expect($this->postPlatform->platform_post_id)->toBe('existing-123');
});

test('publish to social platform saves error context on generic failure', function () {
    Event::fake();

    $this->post->update(['content' => 'Test content here']);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new Exception('Something broke'));

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    expect($this->postPlatform->error_context)->toBeArray();
    expect($this->postPlatform->error_context['category'])->toBe('unknown');
    expect($this->postPlatform->error_context['failed_at'])->toBeString();
    expect($this->postPlatform->error_context['content_length'])->toBe(17);
    expect($this->postPlatform->error_context['media_count'])->toBe(0);
});

test('publish to social platform saves error context on social publish exception', function () {
    Event::fake();

    $this->post->update(['content' => 'Hello world']);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new LinkedInPublishException(
            'Not authorized to post',
            ErrorCategory::Permission,
            '403',
            '{"error": "forbidden"}',
        )
    );

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    expect($this->postPlatform->error_context)->toBeArray();
    expect($this->postPlatform->error_context['category'])->toBe('permission');
    expect($this->postPlatform->error_context['platform_error_code'])->toBe('403');
    expect($this->postPlatform->error_context['content_length'])->toBe(11);
    expect($this->postPlatform->error_context['raw_response'])->toBe('{"error": "forbidden"}');
});

test('publish to social platform fails when scopes are missing', function () {
    Event::fake();

    $this->socialAccount->update(['scopes' => ['user.info.basic']]); // missing w_member_social
    $this->postPlatform->refresh();

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    expect($this->postPlatform->status)->toBe(PlatformStatus::Failed);
    expect($this->postPlatform->error_message)->toContain('Missing permissions');
    expect($this->postPlatform->error_context['category'])->toBe('permission');
    expect($this->postPlatform->error_context['missing_scopes'])->toContain('w_member_social');
});

test('publish to social platform saves error context on token expired', function () {
    Event::fake();
    Mail::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new TokenExpiredException('Token expired', '190'));

    $this->app->instance(LinkedInPublisher::class, $publisher);

    $verifier = Mockery::mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->andThrow(new TokenExpiredException('Refresh failed'));
    $this->app->instance(ConnectionVerifier::class, $verifier);

    (new PublishToSocialPlatform($this->postPlatform))->handle();

    $this->postPlatform->refresh();
    expect($this->postPlatform->error_context)->toBeArray();
    expect($this->postPlatform->error_context['category'])->toBe('token_expired');
    expect($this->postPlatform->error_context['platform_error_code'])->toBe('190');
});
