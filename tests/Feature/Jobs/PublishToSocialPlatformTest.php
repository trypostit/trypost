<?php

declare(strict_types=1);

use App\Enums\GoogleBusiness\LocalPostState;
use App\Enums\GoogleBusiness\TopicType;
use App\Enums\Notification\Type;
use App\Enums\Post\PublishStatus as PlatformStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Enums\TikTok\PrivacyLevel;
use App\Events\PostStatusUpdated;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\BlueskyPublishException;
use App\Exceptions\Social\ContentLimitException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\InstagramPublishException;
use App\Exceptions\Social\LinkedInPublishException;
use App\Exceptions\Social\SocialPublishException;
use App\Exceptions\Social\YouTubePublishException;
use App\Exceptions\TokenExpiredException;
use App\Jobs\PublishToSocialPlatform;
use App\Jobs\ResolveTikTokVideoId;
use App\Jobs\SendNotification;
use App\Mail\AccountDisconnected;
use App\Mail\PostPublished;
use App\Mail\PostPublishFailed;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\BlueskyPublisher;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\FacebookPublisher;
use App\Services\Social\LinkedInPagePublisher;
use App\Services\Social\LinkedInPublisher;
use App\Services\Social\PinterestPublisher;
use App\Services\Social\TikTokPublisher;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Mail::fake();
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->socialAccount = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $this->post = Post::factory()->forAccount($this->socialAccount)->scheduled()->create([
        'user_id' => $this->user->id,
    ]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function publishJobRetarget(Post $post, SocialAccount $account, array $attributes = []): Post
{
    $post->forceFill([
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'content_type' => ContentType::defaultFor($account->platform),
        'meta' => [],
        ...$attributes,
    ])->save();

    return $post->fresh();
}

test('publish to social platform marks platform as publishing', function () {
    Event::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andReturn([
        'id' => 'post-123',
        'url' => 'https://linkedin.com/post/123',
    ]);

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    Event::assertDispatched(PostStatusUpdated::class);
});

test('publish to social platform marks platform as published on success', function () {
    Event::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andReturn([
        'id' => 'post-123',
        'url' => 'https://linkedin.com/post/123',
    ]);

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    expect($this->post->publish_status)->toBe(PlatformStatus::Published);
    expect($this->post->platform_post_id)->toBe('post-123');
    expect($this->post->platform_url)->toBe('https://linkedin.com/post/123');
});

test('publish job dispatches a linkedin-page post to the page publisher', function () {
    Event::fake();

    $workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $pageAccount = SocialAccount::factory()->linkedinPage()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $this->user->id,
    ]);
    $pagePost = publishJobRetarget($post, $pageAccount, [
        'platform' => Platform::LinkedInPage,
        'content_type' => ContentType::LinkedInPagePost,
    ]);

    $publisher = Mockery::mock(LinkedInPagePublisher::class);
    $publisher->shouldReceive('publish')->once()->andReturn([
        'id' => 'org-post-1',
        'url' => 'https://linkedin.com/company/post/1',
    ]);
    $this->app->instance(LinkedInPagePublisher::class, $publisher);

    (new PublishToSocialPlatform($pagePost))->handle();

    $pagePost->refresh();
    expect($pagePost->publish_status)->toBe(PlatformStatus::Published);
    expect($pagePost->platform_post_id)->toBe('org-post-1');
});

test('publish job runs the real document flow end-to-end for a LinkedIn PDF post', function () {
    Event::fake();

    // Real publisher (no mock) — exercise the full job -> getPublisher -> publishDocument chain.
    $this->socialAccount->update([
        'platform_user_id' => 'person-xyz',
        'token_expires_at' => now()->addDays(60),
    ]);
    $this->post->update(['content_type' => ContentType::LinkedInPost]);
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

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    expect($this->post->publish_status)->toBe(PlatformStatus::Published);
    expect($this->post->platform_post_id)->toBe('urn:li:share:e2e');

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

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    expect($this->post->publish_status)->toBe(PlatformStatus::Failed);
    expect($this->post->error_message)->toBe('An unexpected error occurred while publishing. Please try again.');
});

test('publish keeps the vetted user message from a publish exception', function () {
    Event::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new LinkedInPublishException(
        userMessage: 'LinkedIn rejected this post.',
        category: ErrorCategory::ContentPolicy,
    ));

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    expect($this->post->publish_status)->toBe(PlatformStatus::Failed);
    expect($this->post->error_message)->toBe('LinkedIn rejected this post.');
});

test('publish reports an unmapped publish exception so Nightwatch sees it', function () {
    Event::fake();
    Exceptions::fake();

    $exception = new LinkedInPublishException(
        userMessage: 'Something LinkedIn has not told us about.',
        category: ErrorCategory::Unknown,
    );

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow($exception);

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(LinkedInPublishException::class);
    $this->post->refresh();
    expect($this->post->publish_status)->toBe(PlatformStatus::Failed)
        ->and($this->post->error_message)->toBe($exception->userMessage);
});

test('publish logs but does not report a documented rejection the user must act on', function (LinkedInPublishException $exception) {
    Event::fake();
    Exceptions::fake();
    Log::spy();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow($exception);

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    Exceptions::assertNothingReported();
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Social publish failed'
            && $context['exception'] === LinkedInPublishException::class)
        ->once();
    $this->post->refresh();
    expect($this->post->publish_status)->toBe(PlatformStatus::Failed)
        ->and($this->post->error_message)->toBe($exception->userMessage);
})->with([
    'permission' => fn () => linkedInRejection(403),
]);

test('a Bluesky email rejection saves actionable failure details without refreshing the valid account', function () {
    $this->socialAccount->update(['platform' => Platform::Bluesky]);
    $this->post->update(['platform' => Platform::Bluesky, 'content_type' => ContentType::BlueskyPost]);
    Event::fake();
    Exceptions::fake();
    Queue::fake();

    $body = ['jobStatus' => ['error' => 'unconfirmed_email']];
    $response = Http::fake(['*' => Http::response($body, 401)])
        ->post('https://video.bsky.app/xrpc/app.bsky.video.uploadVideo');
    $exception = BlueskyPublishException::fromApiResponse($response);

    $this->mock(BlueskyPublisher::class)->shouldReceive('publish')->once()->andThrow($exception);
    $this->mock(ConnectionVerifier::class)->shouldNotReceive('refreshToken');

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    expect($this->post->publish_status)->toBe(PlatformStatus::Failed)
        ->and($this->post->error_message)->toBe('Confirm your email in Bluesky settings, then try publishing again.')
        ->and(data_get($this->post->error_context, 'category'))->toBe(ErrorCategory::Permission->value)
        ->and(data_get($this->post->error_context, 'platform_error_code'))->toBe('unconfirmed_email')
        ->and(json_decode(data_get($this->post->error_context, 'raw_response'), true))->toBe($body)
        ->and($this->socialAccount->fresh()->status)->toBe(AccountStatus::Connected);

    Queue::assertNotPushed(PublishToSocialPlatform::class);
    Exceptions::assertNothingReported();
});

test('publish reports a failure that can be ours, even when categorized', function (SocialPublishException $exception) {
    Event::fake();
    Exceptions::fake();
    Log::spy();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow($exception);

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    Exceptions::assertReported($exception::class);
    expect($this->post->refresh()->publish_status)->toBe(PlatformStatus::Failed);
})->with([
    'server error' => fn () => new LinkedInPublishException(
        userMessage: 'LinkedIn could not process the media.',
        category: ErrorCategory::ServerError,
        platformErrorCode: 'media-processing-timeout',
        rawResponse: '{"status":"ERROR"}',
    ),
    'content over the limit at publish time' => fn () => ContentLimitException::exceeds(Platform::LinkedIn, 3000, 3200),
    'our own media failure' => fn () => new LinkedInPublishException(
        userMessage: 'Unsupported video format.',
        category: ErrorCategory::MediaFormat,
    ),
    'our own configuration failure' => fn () => new LinkedInPublishException(
        userMessage: 'LinkedIn organization ID not configured.',
        category: ErrorCategory::Permission,
    ),
    'a mapped server error' => fn () => linkedInRejection(500),
    'a request LinkedIn could not process' => fn () => linkedInRejection(422),
]);

test('publish reports a YouTube download that came back empty, though it is a media format failure', function () {
    Event::fake();
    Exceptions::fake();

    $account = SocialAccount::factory()->youtube()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addDays(7),
    ]);
    $this->post->update(['content' => 'A short', 'media' => [[
        'id' => 'video-1',
        'type' => 'video',
        'path' => 'medias/video.mp4',
        'url' => 'https://example.com/video.mp4',
        'mime_type' => 'video/mp4',
        'original_filename' => 'video.mp4',
    ]]]);
    $channelPost = publishJobRetarget($this->post, $account, [
        'content_type' => ContentType::YouTubeShort,
        'scheduled_before_media_checks' => true,
    ]);
    Http::fake(['https://example.com/video.mp4' => Http::response('tiny')]);

    (new PublishToSocialPlatform($channelPost))->handle();

    Exceptions::assertReported(fn (YouTubePublishException $exception): bool => $exception->category === ErrorCategory::MediaFormat
        && str_contains($exception->userMessage, 'Downloaded video is too small or empty'));
    expect($channelPost->fresh()->publish_status)->toBe(PlatformStatus::Failed);
});

function linkedInRejection(int $status): LinkedInPublishException
{
    return LinkedInPublishException::fromApiResponse(
        Http::fake(['*' => Http::response(['message' => 'Rejected', 'status' => $status], $status)])
            ->post(config('trypost.platforms.linkedin.api').'/rest/posts'),
    );
}

test('publish reports unexpected errors so Nightwatch sees them', function () {
    Event::fake();
    Exceptions::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new TypeError('API Error'));

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(TypeError::class);
    $this->post->refresh();
    expect($this->post->error_message)->toBe('An unexpected error occurred while publishing. Please try again.');
});

test('publish does not report a token expiry it settles', function () {
    Event::fake();
    Exceptions::fake();
    Mail::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new TokenExpiredException('Token expired', '401'));

    $this->app->instance(LinkedInPublisher::class, $publisher);

    $verifier = Mockery::mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->andReturnTrue();
    $this->app->instance(ConnectionVerifier::class, $verifier);

    (new PublishToSocialPlatform($this->post))->handle();

    Exceptions::assertNothingReported();
    expect($this->post->fresh()->publish_status)->toBe(PlatformStatus::Failed);
});

test('publish does not report a token refresh that confirms the token is dead', function () {
    Event::fake();
    Exceptions::fake();
    Mail::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new TokenExpiredException('Token expired', '190'));
    $this->app->instance(LinkedInPublisher::class, $publisher);

    $verifier = Mockery::mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->andThrow(new TokenExpiredException('Refresh failed'));
    $this->app->instance(ConnectionVerifier::class, $verifier);

    (new PublishToSocialPlatform($this->post))->handle();

    Exceptions::assertNothingReported();
    expect($this->post->fresh()->publish_status)->toBe(PlatformStatus::Failed);
});

test('publish reports an unexpected error from the token refresh once', function () {
    Event::fake();
    Exceptions::fake();
    Mail::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new TokenExpiredException('Token expired', '190'));
    $this->app->instance(LinkedInPublisher::class, $publisher);

    $verifier = Mockery::mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->andThrow(new RuntimeException('Refresh exploded'));
    $this->app->instance(ConnectionVerifier::class, $verifier);

    (new PublishToSocialPlatform($this->post))->handle();

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Refresh exploded');
    expect($this->post->fresh()->publish_status)->toBe(PlatformStatus::Failed);
});

test('publish does not report a platform-unavailable retry', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Exceptions::fake();
    Mail::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException('LinkedIn 503', 503)
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    Exceptions::assertNothingReported();
    expect($this->post->fresh()->publish_status)->toBe(PlatformStatus::Retrying);
});

test('publish log includes media so Nightwatch can tell a CDN miss from an API rejection', function () {
    Exceptions::fake();

    $this->post->update([
        'media' => [[
            'url' => 'https://cdn.trypost.it/media/2026-01/clip.mp4',
            'mime_type' => 'video/mp4',
            'size' => 4_194_304,
            'path' => 'media/2026-01/clip.mp4',
            'original_filename' => 'clip.mp4',
        ]],
    ]);

    $logs = [];
    Log::listen(function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event;
    });

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new InstagramPublishException(
            userMessage: 'Instagram media processing failed',
            category: ErrorCategory::ServerError,
            rawResponse: '{"status":"ERROR","detail":"download failed"}',
        )
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post->fresh()))->handle();

    $entry = collect($logs)->first(
        fn (MessageLogged $event): bool => $event->message === 'Social publish failed'
    );

    expect($entry)->not->toBeNull()
        ->and($entry->level)->toBe('error')
        ->and(data_get($entry->context, 'platform'))->toBe('linkedin')
        ->and(data_get($entry->context, 'media.0.url'))->toBe('https://cdn.trypost.it/media/2026-01/clip.mp4')
        ->and(data_get($entry->context, 'media.0.mime_type'))->toBe('video/mp4')
        ->and(data_get($entry->context, 'media.0.size'))->toBe(4_194_304)
        ->and(data_get($entry->context, 'media.0.type'))->toBe('video')
        ->and(data_get($entry->context, 'content_type'))->toBe('linkedin_post')
        ->and(data_get($entry->context, 'raw_response'))->toBe('{"status":"ERROR","detail":"download failed"}');
});

test('publish reports when platform-unavailable retries are exhausted', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Exceptions::fake();
    Mail::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException('TikTok is still processing publish_id pub_stuck', 503)
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    $this->post->update([
        'error_context' => ['retry_count' => PublishToSocialPlatform::MAX_PLATFORM_UNAVAILABLE_RETRIES],
    ]);

    (new PublishToSocialPlatform($this->post))->handle();

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(PlatformUnavailableException::class);
    expect($this->post->fresh()->publish_status)->toBe(PlatformStatus::Failed);
});

test('publish never leaks a raw internal error to the failure record (and the email)', function () {
    Event::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new TypeError(
        'X::getMediaCategory(): Argument #1 ($mimeType) must be of type string, null given, called in /home/forge/app.trypost.it/releases/72198060/app/Services/Social/XPublisher.php on line 130'
    ));

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    expect($this->post->publish_status)->toBe(PlatformStatus::Failed);
    expect($this->post->error_message)->toBe('An unexpected error occurred while publishing. Please try again.')
        ->and($this->post->error_message)->not->toContain('/home/forge')
        ->and($this->post->error_message)->not->toContain('getMediaCategory');
});

test('the job-failed hook also genericizes a raw internal error', function () {
    Event::fake();

    (new PublishToSocialPlatform($this->post))->failed(new TypeError(
        'boom in /home/forge/app.trypost.it/releases/72198060/app/Services/Social/XPublisher.php on line 130'
    ));

    $this->post->refresh();
    expect($this->post->publish_status)->toBe(PlatformStatus::Failed)
        ->and($this->post->error_message)->toBe('An unexpected error occurred while publishing. Please try again.')
        ->and($this->post->error_message)->not->toContain('/home/forge');
});

test('publish to social platform marks account as token expired on auth failure', function () {
    Event::fake();
    Mail::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new TokenExpiredException('Token expired', '401'));

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    $this->socialAccount->refresh();

    expect($this->post->publish_status)->toBe(PlatformStatus::Failed);
    expect($this->socialAccount->status)->toBe(AccountStatus::TokenExpired);
});

test('publish reschedules platform unavailable retry via Bus dispatch (not marked Failed, not expired)', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException(
            'LinkedIn API returned 503 during token refresh',
            503,
            ['operation_id' => 'operation-123'],
        )
    );

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    $this->socialAccount->refresh();

    expect($this->post->publish_status)->toBe(PlatformStatus::Retrying);
    expect($this->post->error_context['category'] ?? null)->toBe('platform_unavailable');
    expect($this->post->error_context['http_status'] ?? null)->toBe(503);
    expect($this->post->error_context['retry_count'] ?? null)->toBe(1);
    expect($this->post->error_context['operation_id'] ?? null)->toBe('operation-123');
    expect($this->post->error_message)->toBe(__('posts.errors.platform_unavailable'));
    expect($this->post->error_context['detail'] ?? null)->toContain('LinkedIn API returned 503');
    expect($this->socialAccount->status)->toBe(AccountStatus::Connected);

    Bus::assertDispatched(PublishToSocialPlatform::class, function ($job) {
        return $job->post->id === $this->post->id
            && $job->uniqueAttempt === 1
            && $job->uniqueId() === "{$this->post->id}:1";
    });
});

test('publish reschedules when pinterest video processing times out', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();
    $this->post->update(['media' => [[
        'id' => 'video-1',
        'path' => 'media/2026-01/pin.mp4',
        'url' => 'https://example.com/media/2026-01/pin.mp4',
        'type' => 'video',
        'mime_type' => 'video/mp4',
        'original_filename' => 'pin.mp4',
    ]]]);

    $pinterestAccount = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
        'meta' => ['default_board_id' => 'board_123'],
    ]);

    $channelPost = publishJobRetarget($this->post, $pinterestAccount, [
        'platform' => Platform::Pinterest,
        'content_type' => ContentType::PinterestVideoPin,
        'publish_status' => PlatformStatus::Pending,
        'meta' => ['board_id' => 'board_123'],
    ]);

    $publisher = Mockery::mock(PinterestPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException(
            'Pinterest media processing timeout after 60 attempts (media_id=media_abc, last_status=processing)'
        )
    );
    $this->app->instance(PinterestPublisher::class, $publisher);

    (new PublishToSocialPlatform($channelPost))->handle();

    $channelPost->refresh();

    expect($channelPost->publish_status)->toBe(PlatformStatus::Retrying)
        ->and($channelPost->error_context['category'] ?? null)->toBe('platform_unavailable')
        ->and($channelPost->error_message)->toBe(__('posts.errors.platform_unavailable'))
        ->and($channelPost->error_context['detail'] ?? null)->toContain('Pinterest media processing timeout');

    Bus::assertDispatched(PublishToSocialPlatform::class, function ($job) use ($channelPost) {
        return $job->post->id === $channelPost->id
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

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    $this->socialAccount->refresh();

    expect($this->post->publish_status)->toBe(PlatformStatus::Retrying);
    expect($this->post->error_context['category'] ?? null)->toBe('platform_unavailable');
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

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();

    // error_context tracks the next attempt — must be exactly +10 min
    expect($this->post->error_context['next_attempt_at'] ?? null)
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

test('publish honors a platform-specific retry delay and retry limit', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();

    $now = now()->startOfSecond();
    Carbon::setTestNow($now);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new PlatformUnavailableException(
        message: 'Remote operation is still processing',
        context: ['operation_id' => 'operation-123'],
        retryDelaySeconds: 30,
        maxRetries: 2,
    ));
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();

    expect($this->post->error_context['next_attempt_at'] ?? null)
        ->toBe($now->copy()->addSeconds(30)->toIso8601String());

    Bus::assertDispatched(PublishToSocialPlatform::class, function ($job) use ($now) {
        return $job->delay instanceof DateTimeInterface
            && Carbon::instance($job->delay)->equalTo($now->copy()->addSeconds(30));
    });

    $this->post->update(['error_context' => [...$this->post->error_context, 'processing_retry_count' => 2]]);
    (new PublishToSocialPlatform($this->post->fresh()))->handle();

    expect($this->post->fresh()->publish_status)->toBe(PlatformStatus::Failed);

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

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();

    expect($this->post->error_context['last_attempt_at'] ?? null)
        ->toBe($now->toIso8601String());

    Carbon::setTestNow();
});

test('publish preserves resumable context when a later transient error has no context', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();

    $checkpoint = [
        'instagram_workflow' => [
            'stage' => 'final_container',
            'container_id' => 'container-123',
        ],
        'tiktok_publish_id' => 'publish-123',
        'tiktok_derivative_paths' => ['social-tiktok-photos/pending.jpg'],
        'retry_count' => 2,
    ];
    $this->post->update(['error_context' => $checkpoint]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException('Token refresh service unavailable', 503)
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post->fresh()))->handle();

    $context = $this->post->fresh()->error_context;

    expect($context['instagram_workflow'] ?? null)->toBe($checkpoint['instagram_workflow'])
        ->and($context['tiktok_publish_id'] ?? null)->toBe('publish-123')
        ->and($context['tiktok_derivative_paths'] ?? null)->toBe(['social-tiktok-photos/pending.jpg'])
        ->and($context['retry_count'] ?? null)->toBe(3)
        ->and($context['http_status'] ?? null)->toBe(503);
});

test('an unrelated outage after a processing reschedule does not inherit the processing retry policy', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();
    $this->freezeTime();

    $this->post->update([
        'error_context' => [
            'instagram_workflow' => [
                'stage' => 'final_container',
                'container_id' => 'container-123',
            ],
            'processing_retry_count' => 7,
            'max_retries' => 90,
            'retry_delay_seconds' => 10,
        ],
    ]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException('Token refresh service unavailable', 503)
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post->fresh()))->handle();

    $context = $this->post->fresh()->error_context;

    expect($this->post->fresh()->publish_status)->toBe(PlatformStatus::Retrying)
        ->and($context['instagram_workflow']['container_id'] ?? null)->toBe('container-123')
        ->and($context['retry_count'] ?? null)->toBe(1)
        ->and($context['processing_retry_count'] ?? null)->toBe(7)
        ->and($context)->not->toHaveKey('max_retries')
        ->and($context)->not->toHaveKey('retry_delay_seconds')
        ->and($context['next_attempt_at'] ?? null)->toBe(now()->addSeconds(600)->toIso8601String());
});

test('a processing reschedule keeps counting against its own retry policy', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    $this->freezeTime();

    $this->post->update([
        'error_context' => ['retry_count' => 2, 'processing_retry_count' => 7, 'max_retries' => 30, 'retry_delay_seconds' => 60],
    ]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException('Still processing', retryDelaySeconds: 60, maxRetries: 30)
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post->fresh()))->handle();

    $context = $this->post->fresh()->error_context;

    expect($this->post->fresh()->publish_status)->toBe(PlatformStatus::Retrying)
        ->and($context['processing_retry_count'] ?? null)->toBe(8)
        ->and($context['retry_count'] ?? null)->toBe(2)
        ->and($context['max_retries'] ?? null)->toBe(30)
        ->and($context['retry_delay_seconds'] ?? null)->toBe(60)
        ->and($context['next_attempt_at'] ?? null)->toBe(now()->addSeconds(60)->toIso8601String());
});

test('alternating processing reschedules and outages still exhausts the outage retries', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();

    $this->post->update([
        'error_context' => ['retry_count' => 6, 'processing_retry_count' => 3, 'max_retries' => 30, 'retry_delay_seconds' => 60],
    ]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException('Service unavailable', 503)
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post->fresh()))->handle();

    expect($this->post->fresh()->publish_status)->toBe(PlatformStatus::Failed);
    Bus::assertNotDispatched(PublishToSocialPlatform::class);
});

test('an outage after a processing reschedule is dispatched under a new unique attempt', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();

    $this->post->update([
        'error_context' => ['processing_retry_count' => 1, 'max_retries' => 30, 'retry_delay_seconds' => 60],
    ]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException('Service unavailable', 503)
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post->fresh(), 1))->handle();

    Bus::assertDispatched(PublishToSocialPlatform::class, fn (PublishToSocialPlatform $job): bool => $job->uniqueAttempt === 2);
});

test('an outage on a status poll rescheduled before 2.0 restarts the outage retries', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();
    $this->freezeTime();

    $this->post->update([
        'error_context' => ['retry_count' => 40, 'max_retries' => 90, 'retry_delay_seconds' => 10],
    ]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException('Service unavailable', 503)
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post->fresh(), 40))->handle();

    $context = $this->post->fresh()->error_context;

    expect($this->post->fresh()->publish_status)->toBe(PlatformStatus::Retrying)
        ->and($context['retry_count'] ?? null)->toBe(1)
        ->and($context['processing_retry_count'] ?? null)->toBe(40)
        ->and($context)->not->toHaveKey('max_retries')
        ->and($context['next_attempt_at'] ?? null)->toBe(now()->addSeconds(600)->toIso8601String());

    Bus::assertDispatched(PublishToSocialPlatform::class, fn (PublishToSocialPlatform $job): bool => $job->uniqueAttempt > 40);
});

test('a status poll rescheduled before 2.0 keeps counting its poll budget', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    $this->freezeTime();

    $this->post->update([
        'error_context' => ['retry_count' => 40, 'max_retries' => 90, 'retry_delay_seconds' => 10],
    ]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException('Still processing', retryDelaySeconds: 10, maxRetries: 90)
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post->fresh(), 40))->handle();

    $context = $this->post->fresh()->error_context;

    expect($this->post->fresh()->publish_status)->toBe(PlatformStatus::Retrying)
        ->and($context['processing_retry_count'] ?? null)->toBe(41)
        ->and((int) ($context['retry_count'] ?? 0))->toBe(0)
        ->and($context['max_retries'] ?? null)->toBe(90);

    Bus::assertDispatched(PublishToSocialPlatform::class, fn (PublishToSocialPlatform $job): bool => $job->uniqueAttempt > 40);
});

test('successful publish after a retry transitions the platform to Published', function () {
    // Pre-condition: this platform already failed once and is currently Retrying.
    $this->post->forceFill([
        'publish_status' => PlatformStatus::Retrying,
        'error_context' => ['retry_count' => 3, 'category' => 'platform_unavailable'],
    ])->save();

    Event::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andReturn([
        'id' => 'post-after-retry',
        'url' => 'https://linkedin.com/post/after-retry',
    ]);
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();

    expect($this->post->publish_status)->toBe(PlatformStatus::Published);
    expect($this->post->platform_post_id)->toBe('post-after-retry');
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
    $this->post->update([
        'error_context' => ['retry_count' => 5],
    ]);

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();

    expect($this->post->publish_status)->toBe(PlatformStatus::Retrying);
    expect($this->post->error_context['retry_count'] ?? null)->toBe(6);
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

    $this->post->update([
        'error_context' => ['retry_count' => PublishToSocialPlatform::MAX_PLATFORM_UNAVAILABLE_RETRIES],
    ]);

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();

    expect($this->post->publish_status)->toBe(PlatformStatus::Failed)
        ->and($this->post->error_message)->toBe(__('posts.errors.platform_unavailable_exhausted'))
        ->and($this->post->error_context['category'] ?? null)->toBe('platform_unavailable')
        ->and($this->post->error_context['retry_count'] ?? null)->toBe(PublishToSocialPlatform::MAX_PLATFORM_UNAVAILABLE_RETRIES + 1)
        ->and($this->post->error_context['detail'] ?? null)->toContain('LinkedIn 503');

    Bus::assertNotDispatched(PublishToSocialPlatform::class);
});

test('publish keeps a resumable checkpoint when platform unavailable retries are exhausted', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();

    $workflow = [
        'stage' => 'final_container',
        'container_id' => 'container-123',
    ];
    $this->post->update([
        'error_context' => [
            'tiktok_publish_id' => 'pub_in_flight',
            'instagram_workflow' => $workflow,
            'retry_count' => PublishToSocialPlatform::MAX_PLATFORM_UNAVAILABLE_RETRIES,
        ],
    ]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new PlatformUnavailableException('LinkedIn 503', 503)
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post->fresh()))->handle();

    $context = $this->post->fresh()->error_context;

    expect($this->post->fresh()->publish_status)->toBe(PlatformStatus::Failed)
        ->and($context['tiktok_publish_id'] ?? null)->toBe('pub_in_flight')
        ->and($context['instagram_workflow'] ?? null)->toBe($workflow)
        ->and($context['category'] ?? null)->toBe('platform_unavailable');

    Bus::assertNotDispatched(PublishToSocialPlatform::class);
});

test('publish skips platforms that are already failed', function () {
    Event::fake();
    Mail::fake();

    $this->post->forceFill([
        'publish_status' => PlatformStatus::Failed,
        'error_message' => __('posts.errors.publishing_timed_out'),
    ])->save();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldNotReceive('publish');
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();

    expect($this->post->publish_status)->toBe(PlatformStatus::Failed)
        ->and($this->post->error_message)->toBe(__('posts.errors.publishing_timed_out'));
});

test('publish job timeout leaves headroom above the pinterest media poll budget', function () {
    $job = new PublishToSocialPlatform($this->post);
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
    $job = new PublishToSocialPlatform($this->post, 3);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe("{$this->post->id}:3")
        ->and($job->uniqueFor)->toBe(960);
});

test('publish job prevents concurrent execution across different attempts', function () {
    $job = new PublishToSocialPlatform($this->post, 3);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe("social-publish:{$this->post->id}")
        ->and($middleware[0]->releaseAfter)->toBe(60)
        ->and($middleware[0]->expiresAfter)->toBe($job->timeout + 60)
        ->and($job->tries)->toBe(20)
        ->and($job->maxExceptions)->toBe(1);
});

test('publish job releases an overlapping execution for the same platform', function () {
    $runningJob = new PublishToSocialPlatform($this->post, 0);
    $overlappingJob = (new PublishToSocialPlatform($this->post, 1))->withFakeQueueInteractions();
    /** @var WithoutOverlapping $middleware */
    $middleware = $overlappingJob->middleware()[0];
    $lock = Cache::lock($middleware->getLockKey($runningJob), $runningJob->timeout + 60);
    $handled = false;

    expect($middleware->getLockKey($runningJob))->toBe($middleware->getLockKey($overlappingJob))
        ->and($lock->get())->toBeTrue();

    try {
        $middleware->handle($overlappingJob, function () use (&$handled): void {
            $handled = true;
        });
    } finally {
        $lock->release();
    }

    expect($handled)->toBeFalse();
    $overlappingJob->assertReleased(60);

    $middleware->handle($overlappingJob, function () use (&$handled): void {
        $handled = true;
    });

    expect($handled)->toBeTrue();
});

test('publish job unique lock drops a duplicate dispatch for the same platform attempt', function () {
    Bus::fake([PublishToSocialPlatform::class]);

    PublishToSocialPlatform::dispatch($this->post, 0);
    PublishToSocialPlatform::dispatch($this->post, 0);

    Bus::assertDispatchedTimes(PublishToSocialPlatform::class, 1);
});

test('publish job unique lock allows concurrent dispatches for different attempts', function () {
    Bus::fake([PublishToSocialPlatform::class]);

    PublishToSocialPlatform::dispatch($this->post, 0);
    PublishToSocialPlatform::dispatch($this->post, 1);

    Bus::assertDispatchedTimes(PublishToSocialPlatform::class, 2);
    Bus::assertDispatched(PublishToSocialPlatform::class, fn ($job) => $job->uniqueAttempt === 0);
    Bus::assertDispatched(PublishToSocialPlatform::class, fn ($job) => $job->uniqueAttempt === 1);
});

test('failed hook skips platforms that are already failed', function () {
    Event::fake();
    Mail::fake();

    $this->post->forceFill([
        'publish_status' => PlatformStatus::Failed,
        'error_message' => __('posts.errors.publishing_timed_out'),
        'error_context' => ['category' => 'timeout'],
    ])->save();

    (new PublishToSocialPlatform($this->post))->failed(new TypeError('Simulated worker kill'));

    $this->post->refresh();

    expect($this->post->publish_status)->toBe(PlatformStatus::Failed)
        ->and($this->post->error_message)->toBe(__('posts.errors.publishing_timed_out'))
        ->and($this->post->error_context['category'] ?? null)->toBe('timeout');
});

test('failed hook skips platforms that are already published', function () {
    Event::fake();
    Mail::fake();

    $this->post->forceFill([
        'publish_status' => PlatformStatus::Published,
        'platform_post_id' => 'already-published',
        'error_message' => null,
    ])->save();

    (new PublishToSocialPlatform($this->post))->failed(new TypeError('Simulated worker kill'));

    $this->post->refresh();

    expect($this->post->publish_status)->toBe(PlatformStatus::Published)
        ->and($this->post->platform_post_id)->toBe('already-published')
        ->and($this->post->error_message)->toBeNull();
});

test('failed hook keeps TikTok photo derivatives while a publish_id can be resumed', function () {
    Event::fake();
    Mail::fake();
    Storage::fake();

    $path = 'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
    $unrelatedPath = 'customer-media/keep.jpg';
    Storage::put($path, 'image');
    Storage::put($unrelatedPath, 'image');
    $this->post->forceFill([
        'platform' => Platform::TikTok,
        'publish_status' => PlatformStatus::Retrying,
        'error_context' => [
            'tiktok_publish_id' => 'publish-123',
            'tiktok_derivative_paths' => [$path, 'social-tiktok-photos/../customer-media/keep.jpg'],
        ],
    ])->save();

    (new PublishToSocialPlatform($this->post->fresh()))->failed(new TypeError('Simulated worker kill'));

    Storage::assertExists($path);
    Storage::assertExists($unrelatedPath);
    expect($this->post->fresh()->error_context)->toMatchArray([
        'tiktok_publish_id' => 'publish-123',
        'category' => 'job_failed',
    ]);
});

test('failed hook prunes TikTok photo derivatives when there is no publish_id', function () {
    Event::fake();
    Mail::fake();
    Storage::fake();

    $path = 'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::put($path, 'image');
    $this->post->forceFill([
        'platform' => Platform::TikTok,
        'publish_status' => PlatformStatus::Retrying,
        'error_context' => [
            'tiktok_derivative_paths' => [$path],
        ],
    ])->save();

    (new PublishToSocialPlatform($this->post->fresh()))->failed(new TypeError('Simulated worker kill'));

    Storage::assertMissing($path);
    expect($this->post->fresh()->error_context['category'] ?? null)->toBe('job_failed');
});

test('terminal TikTok account guards keep derivatives while a publish_id can be resumed', function (string $guard) {
    Event::fake();
    Mail::fake();
    Storage::fake();

    $path = 'social-tiktok-photos/'.fake()->uuid().'.jpg';
    Storage::put($path, 'image');

    $accountAttributes = match ($guard) {
        'disconnected' => ['status' => AccountStatus::Disconnected],
        'token_expired' => ['status' => AccountStatus::TokenExpired],
        'missing_scopes' => ['scopes' => []],
    };
    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        ...$accountAttributes,
    ]);
    $platform = publishJobRetarget($this->post, $account, [
        'meta' => ['privacy_level' => PrivacyLevel::SelfOnly->value],
        'publish_status' => PlatformStatus::Retrying,
        'error_context' => [
            'tiktok_publish_id' => "publish-{$guard}",
            'tiktok_derivative_paths' => [$path],
        ],
    ]);

    (new PublishToSocialPlatform($platform))->handle();

    Storage::assertExists($path);
    $platform->refresh();

    expect($platform->publish_status)->toBe(PlatformStatus::Failed)
        ->and($platform->error_context['tiktok_publish_id'] ?? null)->toBe("publish-{$guard}");

    if ($guard === 'missing_scopes') {
        expect($platform->error_message)->toBe('Missing permissions: video.publish. Please reconnect your account.')
            ->and($platform->error_context['category'] ?? null)->toBe('permission')
            ->and($platform->error_context['missing_scopes'] ?? null)->toBe(['video.publish']);

        return;
    }

    $translationKey = match ($guard) {
        'disconnected' => 'posts.errors.account_disconnected',
        'token_expired' => 'posts.errors.account_token_expired',
    };

    expect($platform->error_message)->toBe(__($translationKey));
})->with([
    'disconnected account' => 'disconnected',
    'expired token' => 'token_expired',
    'missing publish scopes' => 'missing_scopes',
]);

test('terminal TikTok account guards prune derivatives when there is no publish_id', function (string $guard) {
    Event::fake();
    Mail::fake();
    Storage::fake();

    $path = 'social-tiktok-photos/'.fake()->uuid().'.jpg';
    Storage::put($path, 'image');

    $accountAttributes = match ($guard) {
        'disconnected' => ['status' => AccountStatus::Disconnected],
        'token_expired' => ['status' => AccountStatus::TokenExpired],
        'missing_scopes' => ['scopes' => []],
    };
    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        ...$accountAttributes,
    ]);
    $platform = publishJobRetarget($this->post, $account, [
        'meta' => ['privacy_level' => PrivacyLevel::SelfOnly->value],
        'publish_status' => PlatformStatus::Retrying,
        'error_context' => [
            'tiktok_derivative_paths' => [$path],
        ],
    ]);

    (new PublishToSocialPlatform($platform))->handle();

    Storage::assertMissing($path);
    expect($platform->fresh()->publish_status)->toBe(PlatformStatus::Failed)
        ->and($platform->fresh()->error_context['tiktok_publish_id'] ?? null)->toBeNull();
})->with([
    'disconnected account' => 'disconnected',
    'expired token' => 'token_expired',
    'missing publish scopes' => 'missing_scopes',
]);

test('tiktok photo publish resumes after a status-fetch token expiry without a second init', function () {
    Event::fake();
    Mail::fake();
    Storage::fake();

    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'tiktoker',
        'token_expires_at' => now()->addDay(),
    ]);
    $this->post->update([
        'media' => [[
            'id' => 'oversized',
            'path' => 'media/2026-01/big.jpg',
            'url' => 'https://example.com/media/2026-01/big.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'big.jpg',
            'meta' => ['width' => 1254, 'height' => 1254],
        ]],
    ]);
    $platform = publishJobRetarget($this->post, $account, [
        'meta' => ['privacy_level' => PrivacyLevel::SelfOnly->value],
        'content_type' => ContentType::TikTokPhoto,
        'publish_status' => PlatformStatus::Pending,
        'meta' => ['privacy_level' => PrivacyLevel::SelfOnly->value],
    ]);

    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('maxWidthForPlatform')->with(Platform::TikTok)->andReturn(1080);
    $mockOptimizer->shouldReceive('optimizeImage')->with(Mockery::type('string'), Platform::TikTok)->andReturnUsing(function (string $tempFile) {
        $optimized = tempnam(sys_get_temp_dir(), 'tt_opt_');
        copy($tempFile, $optimized);

        return $optimized;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    $verifier = Mockery::mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andReturn(true);
    $this->app->instance(ConnectionVerifier::class, $verifier);

    $api = config('trypost.platforms.tiktok.api');

    Http::fake([
        $api.'/post/publish/content/init/' => Http::response(['data' => ['publish_id' => 'pub_job_401']]),
        $api.'/post/publish/status/fetch/' => Http::sequence()
            ->push([
                'error' => [
                    'code' => 'access_token_invalid',
                    'message' => 'Access token is invalid',
                ],
            ], 401)
            ->push([
                'data' => [
                    'status' => 'PUBLISH_COMPLETE',
                    'publicaly_available_post_id' => ['7000000000000000123'],
                ],
            ]),
        '*' => Http::response('fake-image-content', 200),
    ]);

    (new PublishToSocialPlatform($platform))->handle();

    $platform->refresh();

    expect($platform->publish_status)->toBe(PlatformStatus::Published)
        ->and($platform->platform_post_id)->toBe('7000000000000000123')
        ->and($platform->error_context)->toBeNull()
        ->and(Storage::allFiles('social-tiktok-photos'))->toBeEmpty()
        ->and(Http::recorded(fn ($request) => str_contains($request->url(), '/post/publish/content/init/')))
        ->toHaveCount(1);
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

    $channelPost = publishJobRetarget($this->post, $pinterestAccount, [
        'platform' => Platform::Pinterest,
        'content_type' => ContentType::PinterestVideoPin,
        'publish_status' => PlatformStatus::Pending,
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

    (new PublishToSocialPlatform($channelPost))->handle();

    $channelPost->refresh();
    $pinterestAccount->refresh();

    expect($channelPost->publish_status)->toBe(PlatformStatus::Failed)
        ->and($channelPost->error_context['category'] ?? null)->toBe('token_expired')
        ->and($pinterestAccount->status)->toBe(AccountStatus::TokenExpired);

    Queue::assertPushed(SendNotification::class, function ($job) use ($pinterestAccount) {
        return $job->type === Type::AccountDisconnected
            && $job->mailable instanceof AccountDisconnected
            && $job->mailable->account->is($pinterestAccount);
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

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    expect($this->post->status)->toBe(PostStatus::Published);
});

test('publish to social platform marks post as failed when all platforms fail', function () {
    Event::fake();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new Exception('API Error'));

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

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

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    expect($this->post->publish_status)->toBe(PlatformStatus::Failed);
    expect($this->post->error_message)->toBe(__('posts.errors.account_disconnected'));
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

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    expect($this->post->publish_status)->toBe(PlatformStatus::Failed);
    expect($this->post->error_message)->toBe(__('posts.errors.account_token_expired'));
    expect($this->post->error_context['category'])->toBe('token_expired');
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

    (new PublishToSocialPlatform($this->post))->handle();

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

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    expect($this->post->status)->toBe(PostStatus::Failed);
    Queue::assertPushed(SendNotification::class);
});

test('a published post queues the published email for the owner', function () {
    Event::fake();
    Queue::fake();

    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'username' => null,
        'display_name' => 'InboxPlacement.io',
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $channelPost = publishJobRetarget($post, $account, [
        'platform' => $account->platform,
    ]);

    $publisher = Mockery::mock(FacebookPublisher::class);
    $publisher->shouldReceive('publish')->andReturn([
        'id' => 'fb-123',
        'url' => 'https://www.facebook.com/permalink.php?story_fbid=pfbid0&id=61592851040951',
    ]);
    $this->app->instance(FacebookPublisher::class, $publisher);

    (new PublishToSocialPlatform($channelPost))->handle();

    Queue::assertPushed(SendNotification::class, function (SendNotification $job) use ($post) {
        return $job->type === Type::PostPublished
            && $job->user->is($this->user)
            && $job->mailable instanceof PostPublished
            && $job->mailable->post->is($post);
    });
});

test('a failed post queues the failed email for the owner', function () {
    Event::fake();
    Queue::fake();

    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'username' => null,
        'display_name' => 'InboxPlacement.io',
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $channelPost = publishJobRetarget($post, $account, [
        'platform' => $account->platform,
    ]);

    $publisher = Mockery::mock(FacebookPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new Exception('API error'));
    $this->app->instance(FacebookPublisher::class, $publisher);

    (new PublishToSocialPlatform($channelPost))->handle();

    Queue::assertPushed(SendNotification::class, function (SendNotification $job) use ($post) {
        return $job->type === Type::PostFailed
            && $job->user->is($this->user)
            && $job->mailable instanceof PostPublishFailed
            && $job->mailable->post->is($post);
    });
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

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    $this->socialAccount->refresh();

    expect($this->post->publish_status)->toBe(PlatformStatus::Published);
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

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    $this->socialAccount->refresh();

    expect($this->post->publish_status)->toBe(PlatformStatus::Failed);
    expect($this->socialAccount->status)->toBe(AccountStatus::TokenExpired);
});

test('publish to social platform skips if already published (idempotency)', function () {
    Event::fake();

    // Mark as already published
    $this->post->forceFill([
        'publish_status' => PlatformStatus::Published,
        'platform_post_id' => 'existing-123',
    ])->save();

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldNotReceive('publish');

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    // Should not have called publish
    $this->post->refresh();
    expect($this->post->platform_post_id)->toBe('existing-123');
});

test('publish to social platform saves error context on generic failure', function () {
    Event::fake();

    $this->post->update(['content' => 'Test content here']);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new Exception('Something broke'));

    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    expect($this->post->error_context)->toBeArray();
    expect($this->post->error_context['category'])->toBe('unknown');
    expect($this->post->error_context['failed_at'])->toBeString();
    expect($this->post->error_context['content_length'])->toBe(17);
    expect($this->post->error_context['media_count'])->toBe(0);
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

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    expect($this->post->error_context)->toBeArray();
    expect($this->post->error_context['category'])->toBe('permission');
    expect($this->post->error_context['platform_error_code'])->toBe('403');
    expect($this->post->error_context['content_length'])->toBe(11);
    expect($this->post->error_context['raw_response'])->toBe('{"error": "forbidden"}');
});

test('publish keeps a resumable checkpoint when a later publish exception is terminal', function () {
    Event::fake();

    $workflow = [
        'stage' => 'final_container',
        'container_id' => 'container-123',
    ];
    $this->post->update([
        'error_context' => [
            'tiktok_publish_id' => 'pub_dead',
            'instagram_workflow' => $workflow,
        ],
    ]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(
        new LinkedInPublishException(
            'Video rejected',
            ErrorCategory::ContentPolicy,
            'video_rejected',
            '{"status":"FAILED"}',
        )
    );
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post->fresh()))->handle();

    $context = $this->post->fresh()->error_context;

    expect($this->post->fresh()->publish_status)->toBe(PlatformStatus::Failed)
        ->and($context['tiktok_publish_id'] ?? null)->toBe('pub_dead')
        ->and($context['instagram_workflow'] ?? null)->toBe($workflow)
        ->and($context['category'] ?? null)->toBe('content_policy');
});

test('publish to social platform fails when scopes are missing', function () {
    Event::fake();

    $this->socialAccount->update(['scopes' => ['user.info.basic']]); // missing w_member_social
    $this->post->refresh();

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    expect($this->post->publish_status)->toBe(PlatformStatus::Failed);
    expect($this->post->error_message)->toContain('Missing permissions');
    expect($this->post->error_context['category'])->toBe('permission');
    expect($this->post->error_context['missing_scopes'])->toContain('w_member_social');
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

    (new PublishToSocialPlatform($this->post))->handle();

    $this->post->refresh();
    expect($this->post->error_context)->toBeArray();
    expect($this->post->error_context['category'])->toBe('token_expired');
    expect($this->post->error_context['platform_error_code'])->toBe('190');
});

test('dispatches google business posts to GoogleBusinessPublisher', function () {
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addHour(),
    ]);
    $channelPost = publishJobRetarget($this->post, $account, [
        'meta' => ['topic_type' => TopicType::Standard->value],
    ]);

    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/1/locations/2/localPosts/3',
            'state' => LocalPostState::Live->value,
        ], 200),
    ]);

    (new PublishToSocialPlatform($channelPost))->handle();

    expect($channelPost->fresh()->publish_status)->toBe(PlatformStatus::Published);
});

test('a google business post rejected in review is not reported as published', function () {
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addHour(),
    ]);
    $channelPost = publishJobRetarget($this->post, $account, [
        'meta' => ['topic_type' => 'STANDARD'],
    ]);

    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/1/locations/2/localPosts/3',
            'state' => 'REJECTED',
        ], 200),
    ]);

    (new PublishToSocialPlatform($channelPost))->handle();

    $this->assertDatabaseHas('posts', [
        'id' => $channelPost->id,
        'publish_status' => 'rejected',
    ]);
    expect($channelPost->fresh()->error_message)
        ->toBe(__('posts.errors.rejected_in_review'))
        ->and($channelPost->fresh()->platform_post_id)->toBe('accounts/1/locations/2/localPosts/3')
        ->and($channelPost->fresh()->platform_url)->toBe('https://business.google.com/dashboard/l/u987654321');
});

test('a rejected google business target finalizes the post instead of leaving it publishing', function () {
    Queue::fake([SendNotification::class]);
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addHour(),
    ]);
    // Its own post: the shared one carries a LinkedIn target that never finishes.
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $channelPost = publishJobRetarget($post, $account, [
        'meta' => ['topic_type' => 'STANDARD'],
    ]);

    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/1/locations/2/localPosts/3',
            'state' => 'REJECTED',
        ], 200),
    ]);

    (new PublishToSocialPlatform($channelPost))->handle();

    expect($post->fresh()->status)->toBe(PostStatus::Failed);
    Queue::assertPushed(SendNotification::class);
});

test('a google business post still in review is held, not reported as published', function () {
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addHour(),
    ]);
    $channelPost = publishJobRetarget($this->post, $account, [
        'meta' => ['topic_type' => 'STANDARD'],
    ]);

    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/1/locations/2/localPosts/3',
            'state' => 'PROCESSING',
        ], 200),
    ]);

    (new PublishToSocialPlatform($channelPost))->handle();

    $this->assertDatabaseHas('posts', [
        'id' => $channelPost->id,
        'publish_status' => 'pending_review',
    ]);
    expect($channelPost->fresh()->submitted_at)->not->toBeNull()
        ->and($channelPost->fresh()->published_at)->toBeNull();
});

test('a google business post Google scheduled is held in review', function () {
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addHour(),
    ]);
    $channelPost = publishJobRetarget($this->post, $account, [
        'meta' => ['topic_type' => 'STANDARD'],
    ]);

    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/1/locations/2/localPosts/3',
            'state' => 'SCHEDULED',
        ], 200),
    ]);

    (new PublishToSocialPlatform($channelPost))->handle();

    expect($channelPost->fresh()->publish_status)->toBe(PlatformStatus::PendingReview)
        ->and($channelPost->fresh()->platform_post_id)->toBe('accounts/1/locations/2/localPosts/3');
});

test('a google business post that Google reports as recurring is published', function () {
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addHour(),
    ]);
    $channelPost = publishJobRetarget($this->post, $account, [
        'meta' => ['topic_type' => TopicType::Standard->value],
    ]);

    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/1/locations/2/localPosts/3',
            'state' => LocalPostState::Recurring->value,
        ], 200),
    ]);

    (new PublishToSocialPlatform($channelPost))->handle();

    expect($channelPost->fresh()->publish_status)->toBe(PlatformStatus::Published);
});

test('an unspecified google business create state is held in review and keeps the jpeg', function () {
    Storage::fake();
    Storage::put('uploads/promo.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));
    $this->post->update(['media' => [[
        'path' => 'uploads/promo.png',
        'url' => Storage::url('uploads/promo.png'),
        'mime_type' => 'image/png',
        'type' => 'image',
    ]]]);

    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addHour(),
    ]);
    $channelPost = publishJobRetarget($this->post, $account, [
        'meta' => ['topic_type' => TopicType::Standard->value],
    ]);

    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/1/locations/2/localPosts/3',
            'state' => LocalPostState::Unspecified->value,
        ], 200),
    ]);

    (new PublishToSocialPlatform($channelPost))->handle();

    expect($channelPost->fresh()->publish_status)->toBe(PlatformStatus::PendingReview)
        ->and($channelPost->fresh()->published_at)->toBeNull();
    Storage::assertExists(GoogleBusinessDerivativeCleaner::pathFor($channelPost));
});

test('a failed google business publish prunes the jpeg derivative', function () {
    Storage::fake();
    Storage::put('uploads/promo.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));
    $this->post->update(['media' => [[
        'path' => 'uploads/promo.png',
        'url' => Storage::url('uploads/promo.png'),
        'mime_type' => 'image/png',
        'type' => 'image',
    ]]]);

    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addHour(),
    ]);
    $channelPost = publishJobRetarget($this->post, $account, [
        'meta' => ['topic_type' => TopicType::Standard->value],
    ]);

    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'summary too long'],
        ], 400),
    ]);

    (new PublishToSocialPlatform($channelPost))->handle();

    expect($channelPost->fresh()->publish_status)->toBe(PlatformStatus::Failed);
    Storage::assertMissing(GoogleBusinessDerivativeCleaner::pathFor($channelPost));
});

test('an unknown google business create state is held in review and keeps the jpeg', function () {
    Storage::fake();
    Storage::put('uploads/promo.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));
    $this->post->update(['media' => [[
        'path' => 'uploads/promo.png',
        'url' => Storage::url('uploads/promo.png'),
        'mime_type' => 'image/png',
        'type' => 'image',
    ]]]);

    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addHour(),
    ]);
    $channelPost = publishJobRetarget($this->post, $account, [
        'meta' => ['topic_type' => TopicType::Standard->value],
    ]);

    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/1/locations/2/localPosts/3',
            'state' => 'NOT_A_REAL_STATE',
        ], 200),
    ]);

    (new PublishToSocialPlatform($channelPost))->handle();

    expect($channelPost->fresh()->publish_status)->toBe(PlatformStatus::PendingReview)
        ->and($channelPost->fresh()->published_at)->toBeNull();
    Storage::assertExists(GoogleBusinessDerivativeCleaner::pathFor($channelPost));
});

test('a publishing parent stays publishing while google business is in review', function () {
    Queue::fake([SendNotification::class]);
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addHour(),
    ]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
    ]);
    $channelPost = publishJobRetarget($post, $account, [
        'meta' => ['topic_type' => TopicType::Standard->value],
    ]);

    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/1/locations/2/localPosts/3',
            'state' => LocalPostState::Processing->value,
        ], 200),
    ]);

    (new PublishToSocialPlatform($channelPost))->handle();

    expect($channelPost->fresh()->publish_status)->toBe(PlatformStatus::PendingReview)
        ->and($post->fresh()->status)->toBe(PostStatus::Publishing);
    Queue::assertNotPushed(SendNotification::class);
});

test('a google business target in review on its own post does not settle or notify', function () {
    Queue::fake([SendNotification::class]);
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addHour(),
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $channelPost = publishJobRetarget($post, $account, [
        'meta' => ['topic_type' => TopicType::Standard->value],
    ]);

    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/1/locations/2/localPosts/3',
            'state' => LocalPostState::Processing->value,
        ], 200),
    ]);

    (new PublishToSocialPlatform($channelPost))->handle();

    expect($channelPost->fresh()->publish_status)->toBe(PlatformStatus::PendingReview)
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);
    Queue::assertNotPushed(SendNotification::class);
});

test('a google business create that is rate limited waits for a retry instead of failing', function () {
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addHour(),
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $channelPost = publishJobRetarget($post, $account, [
        'meta' => ['topic_type' => TopicType::Standard->value],
    ]);

    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'error' => ['status' => 'RESOURCE_EXHAUSTED'],
        ], 429),
    ]);

    (new PublishToSocialPlatform($channelPost))->handle();

    expect($channelPost->fresh()->publish_status)->toBe(PlatformStatus::Retrying)
        ->and($channelPost->fresh()->retry_at)->not->toBeNull()
        ->and(Storage::exists("google-business-derivatives/{$channelPost->id}.jpg"))->toBeFalse()
        ->and($post->fresh()->status)->not->toBe(PostStatus::Failed);
});

test('a google business target already in review is not published a second time', function () {
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addHour(),
    ]);
    $channelPost = publishJobRetarget($this->post, $account, [
        'publish_status' => PlatformStatus::PendingReview,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
        'meta' => ['topic_type' => 'STANDARD'],
    ]);

    Http::fake();

    (new PublishToSocialPlatform($channelPost))->handle();

    Http::assertNothingSent();
    expect($channelPost->fresh()->publish_status)->toBe(PlatformStatus::PendingReview)
        ->and($channelPost->fresh()->platform_post_id)->toBe('accounts/1/locations/2/localPosts/3');
});

test('a thread result stores the reply ids on the target', function () {
    Event::fake();
    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andReturn(['id' => 'root-1', 'url' => 'https://example.com/root-1', 'thread_reply_ids' => ['a', 'b']]);
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    expect($this->post->fresh()->thread_reply_ids)->toEqual(['a', 'b'])
        ->and($this->post->fresh()->platform_post_id)->toBe('root-1')
        ->and($this->post->fresh()->publish_status)->toBe(PlatformStatus::Published);
});

test('a mastodon thread that fails midway is visible and its retry never posts a live segment again', function () {
    Event::fake();
    $account = SocialAccount::factory()->mastodon()->create(['workspace_id' => $this->workspace->id]);
    $this->post->update(['content' => 'Root']);
    $target = publishJobRetarget($this->post, $account, [
        'meta' => ['thread_replies' => ['Two', 'Three']],
    ]);
    $instance = data_get($account->meta, 'instance');
    Http::fake(["{$instance}/api/v1/statuses" => Http::sequence()
        ->push(['id' => '1', 'url' => "{$instance}/@t/1"])
        ->push(['id' => '2', 'url' => "{$instance}/@t/2"])
        ->push(['error' => 'Validation failed'], 422)
        ->push(['id' => '3', 'url' => "{$instance}/@t/3"])]);

    (new PublishToSocialPlatform($target))->handle();

    $failed = $target->fresh();
    expect($failed->publish_status)->toBe(PlatformStatus::Failed)
        ->and($failed->error_message)->toBe(__('posts.errors.thread_incomplete', ['published' => 2, 'total' => 3, 'error' => 'Validation failed']))
        ->and(collect(data_get($failed->error_context, 'thread_progress'))->pluck('id')->all())->toBe(['1', '2']);

    Bus::fake([PublishToSocialPlatform::class]);
    $this->artisan('posts:retry', ['post' => $this->post->id])
        ->expectsConfirmation('Queue a publish attempt for this post?', 'yes')
        ->assertSuccessful();
    (new PublishToSocialPlatform($target->fresh()))->handle();

    $published = $target->fresh();
    expect($published->publish_status)->toBe(PlatformStatus::Published)
        ->and($published->platform_post_id)->toBe('1')
        ->and($published->platform_url)->toBe("{$instance}/@t/1")
        ->and($published->thread_reply_ids)->toEqual(['2', '3'])
        ->and($published->error_context)->toBeNull();
    Http::assertSentCount(4);
    Http::assertSent(fn ($request): bool => data_get($request->data(), 'status') === 'Three' && data_get($request->data(), 'in_reply_to_id') === '2');
});

test('the reply ids are written in the same update that publishes the target', function () {
    Event::fake();
    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->andReturn(['id' => 'root-1', 'url' => 'https://example.com/root-1', 'thread_reply_ids' => ['a', 'b']]);
    $this->app->instance(LinkedInPublisher::class, $publisher);
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'thread_reply_ids')) {
            $writes[] = $query->sql;
        }
    });

    (new PublishToSocialPlatform($this->post))->handle();

    expect($writes)->toHaveCount(1)
        ->and($writes[0])->toContain('platform_post_id')
        ->and($writes[0])->toContain('published_at');
});

test('a thread that stops on any error says how much of it is live', function () {
    Event::fake();
    $account = SocialAccount::factory()->mastodon()->create(['workspace_id' => $this->workspace->id]);
    $this->post->update(['content' => 'Root']);
    $target = publishJobRetarget($this->post, $account, [
        'meta' => ['thread_replies' => ['Two', 'Three']],
    ]);
    $instance = data_get($account->meta, 'instance');
    Http::fake(["{$instance}/api/v1/statuses" => Http::sequence()
        ->push(['id' => '1', 'url' => "{$instance}/@t/1"])
        ->pushFailedConnection()]);

    (new PublishToSocialPlatform($target))->handle();

    expect($target->fresh()->publish_status)->toBe(PlatformStatus::Failed)
        ->and($target->fresh()->error_message)->toBe(__('posts.errors.thread_incomplete', [
            'published' => 1,
            'total' => 3,
            'error' => 'An unexpected error occurred while publishing. Please try again.',
        ]));
});

test('a tiktok post published before TikTok reports its video id asks again a minute later', function (string $platformPostId, PrivacyLevel $privacy, bool $asks) {
    Event::fake();
    Mail::fake();
    Queue::fake([ResolveTikTokVideoId::class]);
    $this->freezeTime();

    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'tiktoker',
        'token_expires_at' => now()->addDay(),
    ]);
    $this->post->update([
        'media' => [[
            'id' => 'photo',
            'path' => 'media/2026-01/photo.jpg',
            'url' => 'https://example.com/media/2026-01/photo.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'photo.jpg',
            'meta' => ['width' => 1080, 'height' => 1080],
        ]],
    ]);
    $post = publishJobRetarget($this->post, $account, [
        'content_type' => ContentType::TikTokPhoto,
        'publish_status' => PlatformStatus::Pending,
        'meta' => ['privacy_level' => $privacy->value],
    ]);

    $publisher = Mockery::mock(TikTokPublisher::class);
    $publisher->shouldReceive('publish')->once()->andReturn(['id' => $platformPostId, 'url' => 'https://www.tiktok.com/@tiktoker']);
    $this->app->instance(TikTokPublisher::class, $publisher);

    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Published);

    $asks
        ? Queue::assertPushedOn(Platform::TikTok->queue(), ResolveTikTokVideoId::class, fn (ResolveTikTokVideoId $job): bool => $job->post->is($post)
            && $job->delay->equalTo(now()->addSeconds(ResolveTikTokVideoId::FIRST_CHECK_AFTER_SECONDS)))
        : Queue::assertNotPushed(ResolveTikTokVideoId::class);
})->with([
    'no video id yet' => ['p_pub_url~v2.pending', PrivacyLevel::PublicToEveryone, true],
    'video id already reported' => ['7694860629638940686', PrivacyLevel::PublicToEveryone, false],
    'private post' => ['p_pub_url~v2.private', PrivacyLevel::SelfOnly, false],
]);

test('a post on another network never asks TikTok for a video id', function () {
    Event::fake();
    Queue::fake([ResolveTikTokVideoId::class]);

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->once()->andReturn([
        'id' => 'urn:li:share:7000000000000000001',
        'url' => 'https://linkedin.com/post/1',
    ]);
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($this->post))->handle();

    expect($this->post->fresh()->publish_status)->toBe(PlatformStatus::Published);
    Queue::assertNotPushed(ResolveTikTokVideoId::class);
});
