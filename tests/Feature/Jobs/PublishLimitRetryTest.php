<?php

declare(strict_types=1);

use App\Enums\Notification\Type;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\BlueskyPublishException;
use App\Exceptions\Social\DiscordPublishException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\FacebookPublishException;
use App\Exceptions\Social\GoogleBusinessPublishException;
use App\Exceptions\Social\InstagramPublishException;
use App\Exceptions\Social\LinkedInPublishException;
use App\Exceptions\Social\MastodonPublishException;
use App\Exceptions\Social\PinterestPublishException;
use App\Exceptions\Social\SocialPublishException;
use App\Exceptions\Social\TelegramPublishException;
use App\Exceptions\Social\ThreadsPublishException;
use App\Exceptions\Social\TikTokPublishException;
use App\Exceptions\Social\XPublishException;
use App\Exceptions\Social\YouTubePublishException;
use App\Jobs\PublishToSocialPlatform;
use App\Jobs\SendNotification;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\BlueskyPublisher;
use App\Services\Social\Discord\DiscordPublisher;
use App\Services\Social\FacebookPublisher;
use App\Services\Social\GoogleBusinessPublisher;
use App\Services\Social\InstagramPublisher;
use App\Services\Social\LinkedInPublisher;
use App\Services\Social\MastodonPublisher;
use App\Services\Social\PinterestPublisher;
use App\Services\Social\Telegram\TelegramPublisher;
use App\Services\Social\ThreadsPublisher;
use App\Services\Social\TikTokPublisher;
use App\Services\Social\XPublisher;
use App\Services\Social\YouTubePublisher;
use App\Support\Social\LimitRetryPolicy;
use App\Support\Social\PublishCheckpoint;
use App\Support\Social\ThreadProgress;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Mail::fake();
    Event::fake();
    Sleep::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'scheduled_at' => now()->subMinute(),
    ]);
});

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function limitRetryResponse(int $status, array $body, array $headers = []): Response
{
    Http::fake(['limit-retry.test/*' => Http::response($body, $status, $headers)]);

    return Http::get('https://limit-retry.test/refusal');
}

function limitRetryTarget(object $test, Platform $platform, array $attributes = []): PostPlatform
{
    $factory = SocialAccount::factory();
    $state = match ($platform) {
        Platform::LinkedIn => $factory->linkedin(),
        Platform::X => $factory->x(),
        Platform::TikTok => $factory->tiktok(),
        Platform::YouTube => $factory->youtube(),
        Platform::Facebook => $factory->facebook(),
        Platform::Instagram => $factory->instagram(),
        Platform::Threads => $factory->threads(),
        Platform::Pinterest => $factory->pinterest(),
        Platform::GoogleBusiness => $factory->googleBusiness(),
        Platform::Bluesky => $factory->bluesky(),
        Platform::Mastodon => $factory->mastodon(),
        Platform::Telegram => $factory->telegram(),
        Platform::Discord => $factory->discord(),
        default => $factory->state(['platform' => $platform, 'scopes' => $platform->requiredPublishScopes()]),
    };
    $account = $state->create(['workspace_id' => $test->workspace->id]);

    return PostPlatform::factory()->create([
        'post_id' => $test->post->id,
        'social_account_id' => $account->id,
        'platform' => $platform,
        'content_type' => ContentType::defaultFor($platform),
        'enabled' => true,
        'status' => PlatformStatus::Pending,
        'scheduled_before_media_checks' => true,
        ...$attributes,
    ]);
}

function limitRetryPublisherThrows(string $publisherClass, SocialPublishException $exception): void
{
    $publisher = Mockery::mock($publisherClass);
    $publisher->shouldReceive('publish')->andThrow($exception);
    app()->instance($publisherClass, $publisher);
}

dataset('network limit refusals', [
    'tiktok rate_limit_exceeded' => [Platform::TikTok, TikTokPublisher::class, fn (): SocialPublishException => TikTokPublishException::fromApiResponse(limitRetryResponse(429, ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Rate limited']]))],
    'tiktok reached_active_user_cap' => [Platform::TikTok, TikTokPublisher::class, fn (): SocialPublishException => TikTokPublishException::fromApiResponse(limitRetryResponse(403, ['error' => ['code' => 'reached_active_user_cap', 'message' => 'Quota']]))],
    'tiktok spam_risk_too_many_posts' => [Platform::TikTok, TikTokPublisher::class, fn (): SocialPublishException => TikTokPublishException::fromFailReason('spam_risk_too_many_posts')],
    'x usage-capped' => [Platform::X, XPublisher::class, fn (): SocialPublishException => XPublishException::fromApiResponse(limitRetryResponse(429, ['type' => 'https://api.twitter.com/2/problems/usage-capped', 'title' => 'Usage capped']))],
    'x rate-limit-exceeded' => [Platform::X, XPublisher::class, fn (): SocialPublishException => XPublishException::fromApiResponse(limitRetryResponse(429, ['type' => 'https://api.twitter.com/2/problems/rate-limit-exceeded']))],
    'x plain 429' => [Platform::X, XPublisher::class, fn (): SocialPublishException => XPublishException::fromApiResponse(limitRetryResponse(429, ['title' => 'Too Many Requests', 'type' => 'about:blank', 'status' => 429]))],
    'facebook code 4' => [Platform::Facebook, FacebookPublisher::class, fn (): SocialPublishException => FacebookPublishException::fromApiResponse(limitRetryResponse(400, ['error' => ['code' => 4, 'message' => 'Application request limit reached']]))],
    'facebook code 32' => [Platform::Facebook, FacebookPublisher::class, fn (): SocialPublishException => FacebookPublishException::fromApiResponse(limitRetryResponse(400, ['error' => ['code' => 32, 'message' => 'Page request limit reached']]))],
    'facebook code 613' => [Platform::Facebook, FacebookPublisher::class, fn (): SocialPublishException => FacebookPublishException::fromApiResponse(limitRetryResponse(400, ['error' => ['code' => 613, 'message' => 'Calls exceeded the rate limit']]))],
    'facebook BUC 80001' => [Platform::Facebook, FacebookPublisher::class, fn (): SocialPublishException => FacebookPublishException::fromApiResponse(limitRetryResponse(400, ['error' => ['code' => 80001, 'message' => 'Too many calls to this Page']]))],
    'instagram BUC 80002' => [Platform::Instagram, InstagramPublisher::class, fn (): SocialPublishException => InstagramPublishException::fromApiResponse(limitRetryResponse(400, ['error' => ['code' => 80002, 'message' => 'Too many calls to this Instagram account']]))],
    'instagram code 17' => [Platform::Instagram, InstagramPublisher::class, fn (): SocialPublishException => InstagramPublishException::fromApiResponse(limitRetryResponse(400, ['error' => ['code' => 17, 'message' => 'User request limit reached']]))],
    'instagram daily publishing limit' => [Platform::Instagram, InstagramPublisher::class, fn (): SocialPublishException => InstagramPublishException::fromApiResponse(limitRetryResponse(400, ['error' => ['code' => 9, 'error_subcode' => 2207042, 'message' => 'Limit']]))],
    'threads code 4' => [Platform::Threads, ThreadsPublisher::class, fn (): SocialPublishException => ThreadsPublishException::fromApiResponse(limitRetryResponse(400, ['error' => ['code' => 4, 'message' => 'Application request limit reached']]))],
    'threads 429' => [Platform::Threads, ThreadsPublisher::class, fn (): SocialPublishException => ThreadsPublishException::fromApiResponse(limitRetryResponse(429, ['error' => ['message' => 'Too many']]))],
    'linkedin 429' => [Platform::LinkedIn, LinkedInPublisher::class, fn (): SocialPublishException => LinkedInPublishException::fromApiResponse(limitRetryResponse(429, ['message' => 'Resource level throttle limit']))],
    'youtube quotaExceeded' => [Platform::YouTube, YouTubePublisher::class, fn (): SocialPublishException => YouTubePublishException::fromApiResponse(limitRetryResponse(403, ['error' => ['message' => 'Quota', 'errors' => [['reason' => 'quotaExceeded']]]]))],
    'youtube rateLimitExceeded' => [Platform::YouTube, YouTubePublisher::class, fn (): SocialPublishException => YouTubePublishException::fromApiResponse(limitRetryResponse(403, ['error' => ['message' => 'Rate', 'errors' => [['reason' => 'rateLimitExceeded']]]]))],
    'youtube uploadLimitExceeded' => [Platform::YouTube, YouTubePublisher::class, fn (): SocialPublishException => YouTubePublishException::fromApiResponse(limitRetryResponse(400, ['error' => ['message' => 'Upload limit', 'errors' => [['reason' => 'uploadLimitExceeded']]]]))],
    'pinterest 429' => [Platform::Pinterest, PinterestPublisher::class, fn (): SocialPublishException => PinterestPublishException::fromApiResponse(limitRetryResponse(429, ['code' => 8, 'message' => 'Rate limited']))],
    'telegram 429' => [Platform::Telegram, TelegramPublisher::class, fn (): SocialPublishException => TelegramPublishException::fromApiResponse(limitRetryResponse(429, ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 5', 'parameters' => ['retry_after' => 5]]))],
    'discord 429' => [Platform::Discord, DiscordPublisher::class, fn (): SocialPublishException => DiscordPublishException::fromApiResponse(limitRetryResponse(429, ['message' => 'You are being rate limited.', 'retry_after' => 1.5, 'global' => false]))],
    'bluesky 429' => [Platform::Bluesky, BlueskyPublisher::class, fn (): SocialPublishException => BlueskyPublishException::fromApiResponse(limitRetryResponse(429, ['error' => 'RateLimitExceeded', 'message' => 'Rate Limit Exceeded']))],
    'mastodon 429' => [Platform::Mastodon, MastodonPublisher::class, fn (): SocialPublishException => MastodonPublishException::fromApiResponse(limitRetryResponse(429, ['error' => 'Too many requests']))],
    'google business RESOURCE_EXHAUSTED' => [Platform::GoogleBusiness, GoogleBusinessPublisher::class, fn (): SocialPublishException => GoogleBusinessPublishException::fromApiResponse(limitRetryResponse(429, ['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded']]))],
]);

test('a network limit waits an hour instead of failing', function (Platform $platform, string $publisherClass, Closure $refusal) {
    Queue::fake();
    $target = limitRetryTarget($this, $platform);
    $exception = $refusal();
    limitRetryPublisherThrows($publisherClass, $exception);

    (new PublishToSocialPlatform($target))->handle();

    $target->refresh();
    expect($exception->category)->toBe(ErrorCategory::RateLimit)
        ->and($target->status)->toBe(PlatformStatus::Retrying)
        ->and($target->retry_at->toIso8601String())->toBe(now()->addHour()->toIso8601String())
        ->and($target->error_message)->toBe($exception->userMessage)
        ->and(data_get($target->error_context, 'category'))->toBe(ErrorCategory::RateLimit->value)
        ->and(data_get($target->error_context, LimitRetryPolicy::ATTEMPTS_KEY))->toBe(1)
        ->and($this->post->fresh()->status)->toBe(PostStatus::Publishing);
    Queue::assertNotPushed(SendNotification::class);
})->with('network limit refusals');

test('a limit retries after 1h, 2h and 4h through the scheduler, then fails with the network message and notifies once', function () {
    Queue::fake();
    $target = limitRetryTarget($this, Platform::LinkedIn);
    limitRetryPublisherThrows(LinkedInPublisher::class, LinkedInPublishException::fromApiResponse(limitRetryResponse(429, ['message' => 'Throttled'])));

    (new PublishToSocialPlatform($target))->handle();

    foreach ([1, 2, 4] as $attempt => $hours) {
        expect($target->fresh()->status)->toBe(PlatformStatus::Retrying)
            ->and($target->fresh()->retry_at->toIso8601String())->toBe(now()->addHours($hours)->toIso8601String());

        $this->travel(($hours * 60) - 1)->minutes();
        $this->artisan('posts:process-scheduled')->assertSuccessful();
        Queue::assertPushed(PublishToSocialPlatform::class, $attempt);

        $this->travel(1)->minute();
        $this->artisan('posts:process-scheduled')->assertSuccessful();
        Queue::assertPushed(PublishToSocialPlatform::class, $attempt + 1);
        Queue::assertNotPushed(SendNotification::class);

        (new PublishToSocialPlatform($target->fresh()))->handle();
    }

    $target->refresh();
    expect($target->status)->toBe(PlatformStatus::Failed)
        ->and($target->retry_at)->toBeNull()
        ->and($target->error_message)->toBe('LinkedIn rate limit reached. Please try again later.')
        ->and(data_get($target->error_context, LimitRetryPolicy::ATTEMPTS_KEY))->toBe(3)
        ->and($this->post->fresh()->status)->toBe(PostStatus::Failed);
    Queue::assertPushed(SendNotification::class, 1);
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job): bool => $job->type === Type::PostFailed);
});

test('a limit that lifts publishes on the next attempt', function () {
    Queue::fake();
    $target = limitRetryTarget($this, Platform::LinkedIn);
    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldReceive('publish')->once()->andThrow(LinkedInPublishException::fromApiResponse(limitRetryResponse(429, ['message' => 'Throttled'])));
    $publisher->shouldReceive('publish')->once()->andReturn(['id' => 'live-1', 'url' => 'https://linkedin.com/live-1']);
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($target))->handle();
    $this->travel(1)->hour();
    $this->artisan('posts:process-scheduled')->assertSuccessful();
    (new PublishToSocialPlatform($target->fresh()))->handle();

    expect($target->fresh()->status)->toBe(PlatformStatus::Published)
        ->and($target->fresh()->retry_at)->toBeNull()
        ->and($target->fresh()->error_context)->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Published);
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job): bool => $job->type === Type::PostPublished);
});

test('a later reset time from the network wins over the backoff, capped at 24 hours', function (array $headers, array $body, int $expectedSeconds) {
    Queue::fake();
    $target = limitRetryTarget($this, Platform::LinkedIn);
    limitRetryPublisherThrows(LinkedInPublisher::class, LinkedInPublishException::fromApiResponse(limitRetryResponse(429, $body, $headers)));

    (new PublishToSocialPlatform($target))->handle();

    expect($target->fresh()->retry_at->toIso8601String())->toBe(now()->addSeconds($expectedSeconds)->toIso8601String());
})->with([
    'Retry-After seconds later than 1h' => [['Retry-After' => '18000'], [], 18000],
    'Retry-After shorter than the backoff' => [['Retry-After' => '60'], [], 3600],
    'Retry-After beyond 24h is capped' => [['Retry-After' => '200000'], [], 86400],
    'Retry-After HTTP date' => [['Retry-After' => 'Tue, 06 Oct 2026 17:00:00 GMT'], [], 18000],
    'X 24-hour user reset' => [['x-user-limit-24hour-reset' => (string) CarbonImmutable::parse('2026-10-07 02:00:00', 'UTC')->getTimestamp()], [], 50400],
    'Mastodon ISO reset' => [['X-RateLimit-Reset' => '2026-10-06T15:00:00.000Z'], [], 10800],
    'Telegram retry_after' => [[], ['parameters' => ['retry_after' => 7200]], 7200],
    'Meta BUC regain access' => [['X-Business-Use-Case-Usage' => json_encode(['123' => [['type' => 'pages', 'call_count' => 100, 'estimated_time_to_regain_access' => 300]]])], [], 18000],
]);

test('a bluesky 429 retries when the pds RateLimit-Reset says the limit lifts', function () {
    Queue::fake();
    $target = limitRetryTarget($this, Platform::Bluesky);
    $resetAt = now()->addHours(3)->startOfSecond();
    limitRetryPublisherThrows(BlueskyPublisher::class, BlueskyPublishException::fromApiResponse(limitRetryResponse(429, ['error' => 'RateLimitExceeded', 'message' => 'Rate Limit Exceeded'], [
        'RateLimit-Limit' => '5000',
        'RateLimit-Remaining' => '0',
        'RateLimit-Reset' => (string) $resetAt->getTimestamp(),
        'RateLimit-Policy' => '5000;w=3600',
    ])));

    (new PublishToSocialPlatform($target))->handle();

    expect($target->fresh()->status)->not->toBe(PlatformStatus::Failed)
        ->and($target->fresh()->retry_at->toIso8601String())->toBe($resetAt->toIso8601String());
});

test('a permanent refusal still fails at once', function (Platform $platform, string $publisherClass, Closure $refusal) {
    Queue::fake();
    $target = limitRetryTarget($this, $platform);
    limitRetryPublisherThrows($publisherClass, $refusal());

    (new PublishToSocialPlatform($target))->handle();

    expect($target->fresh()->status)->toBe(PlatformStatus::Failed)
        ->and($target->fresh()->retry_at)->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Failed);
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job): bool => $job->type === Type::PostFailed);
})->with([
    'tiktok banned from posting' => [Platform::TikTok, TikTokPublisher::class, fn (): SocialPublishException => TikTokPublishException::fromFailReason('spam_risk_user_banned_from_posting')],
    'facebook policy block 368' => [Platform::Facebook, FacebookPublisher::class, fn (): SocialPublishException => FacebookPublishException::fromApiResponse(limitRetryResponse(400, ['error' => ['code' => 368, 'message' => 'Temporarily blocked for policies violations']]))],
    'linkedin invalid content' => [Platform::LinkedIn, LinkedInPublisher::class, fn (): SocialPublishException => LinkedInPublishException::fromApiResponse(limitRetryResponse(422, ['message' => 'Invalid']))],
    'instagram media format' => [Platform::Instagram, InstagramPublisher::class, fn (): SocialPublishException => InstagramPublishException::fromApiResponse(limitRetryResponse(400, ['error' => ['code' => 36003, 'error_subcode' => 2207009, 'message' => 'Aspect ratio']]))],
]);

test('a job that runs before retry_at does nothing', function () {
    $target = limitRetryTarget($this, Platform::LinkedIn, [
        'status' => PlatformStatus::Retrying,
        'retry_at' => now()->addHour(),
        'error_context' => [LimitRetryPolicy::ATTEMPTS_KEY => 1],
    ]);
    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldNotReceive('publish');
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($target))->handle();
    (new PublishToSocialPlatform($target))->failed(new RuntimeException('worker died'));

    expect($target->fresh()->status)->toBe(PlatformStatus::Retrying);
});

test('a due retry survives a worker restart: the scheduler dispatches it once from the database', function () {
    Queue::fake();
    $target = limitRetryTarget($this, Platform::LinkedIn, [
        'status' => PlatformStatus::Retrying,
        'retry_at' => now()->subMinute(),
        'error_context' => [LimitRetryPolicy::ATTEMPTS_KEY => 2],
    ]);
    $disabled = limitRetryTarget($this, Platform::LinkedIn, [
        'enabled' => false,
        'status' => PlatformStatus::Retrying,
        'retry_at' => now()->subMinute(),
    ]);
    $notDue = limitRetryTarget($this, Platform::LinkedIn, [
        'status' => PlatformStatus::Retrying,
        'retry_at' => now()->addMinute(),
    ]);

    $this->artisan('posts:process-scheduled')->assertSuccessful();
    $this->artisan('posts:process-scheduled')->assertSuccessful();

    Queue::assertPushed(PublishToSocialPlatform::class, 1);
    Queue::assertPushed(PublishToSocialPlatform::class, fn (PublishToSocialPlatform $job): bool => $job->postPlatform->is($target)
        && $job->uniqueAttempt === LimitRetryPolicy::UNIQUE_ATTEMPT_OFFSET + 2);
    expect($target->fresh()->retry_at)->toBeNull()
        ->and($target->fresh()->status)->toBe(PlatformStatus::Retrying)
        ->and($disabled->fresh()->retry_at)->not->toBeNull()
        ->and($notDue->fresh()->retry_at)->not->toBeNull();
});

test('recovering stuck posts never fails a target waiting for a limit', function () {
    $target = limitRetryTarget($this, Platform::LinkedIn, [
        'status' => PlatformStatus::Retrying,
        'retry_at' => now()->addHours(3),
        'error_context' => [LimitRetryPolicy::ATTEMPTS_KEY => 2],
    ]);
    $this->travel(2)->hours();

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    expect($target->fresh()->status)->toBe(PlatformStatus::Retrying)
        ->and($this->post->fresh()->status)->toBe(PostStatus::Publishing);
});

test('a claimed retry whose worker died is timed out by recover after an hour', function () {
    Queue::fake();
    $target = limitRetryTarget($this, Platform::LinkedIn, [
        'status' => PlatformStatus::Retrying,
        'retry_at' => now()->subMinute(),
        'error_context' => [LimitRetryPolicy::ATTEMPTS_KEY => 1],
    ]);
    Post::query()->whereKey($this->post->id)->update(['updated_at' => now()->subHours(2)]);

    $this->artisan('posts:process-scheduled')->assertSuccessful();
    $this->travel(61)->minutes();
    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    expect($target->fresh()->status)->toBe(PlatformStatus::Failed)
        ->and($this->post->fresh()->status)->toBe(PostStatus::Failed);
});

test('a multi-target post stays publishing while one target waits, then settles partially published', function () {
    Queue::fake();
    $published = limitRetryTarget($this, Platform::Bluesky, [
        'status' => PlatformStatus::Published,
        'platform_post_id' => 'at://live',
    ]);
    $waiting = limitRetryTarget($this, Platform::LinkedIn);
    limitRetryPublisherThrows(LinkedInPublisher::class, LinkedInPublishException::fromApiResponse(limitRetryResponse(429, ['message' => 'Throttled'])));

    (new PublishToSocialPlatform($waiting))->handle();

    expect($this->post->fresh()->status)->toBe(PostStatus::Publishing);
    Queue::assertNotPushed(SendNotification::class);

    $waiting->update(['error_context' => [...$waiting->fresh()->error_context, LimitRetryPolicy::ATTEMPTS_KEY => 3], 'retry_at' => null]);
    (new PublishToSocialPlatform($waiting->fresh()))->handle();

    expect($waiting->fresh()->status)->toBe(PlatformStatus::Failed)
        ->and($published->fresh()->status)->toBe(PlatformStatus::Published)
        ->and($this->post->fresh()->status)->toBe(PostStatus::PartiallyPublished);
    Queue::assertPushed(SendNotification::class, 1);
});

test('a thread refused for a limit midway resumes after the live segments without posting them again', function () {
    Queue::fake();
    $this->post->update(['content' => 'Root']);
    $target = limitRetryTarget($this, Platform::Mastodon, [
        'content_type' => ContentType::MastodonPost,
        'meta' => ['thread_replies' => ['Two', 'Three']],
    ]);
    $instance = data_get($target->socialAccount->meta, 'instance');
    $limited = ['error' => 'Too many requests'];
    Http::fake(["{$instance}/api/v1/statuses" => Http::sequence()
        ->push(['id' => '1', 'url' => "{$instance}/@t/1"])
        ->push(['id' => '2', 'url' => "{$instance}/@t/2"])
        ->push($limited, 429)
        ->push($limited, 429)
        ->push($limited, 429)
        ->push(['id' => '3', 'url' => "{$instance}/@t/3"])]);

    (new PublishToSocialPlatform($target))->handle();

    $waiting = $target->fresh();
    expect($waiting->status)->toBe(PlatformStatus::Retrying)
        ->and(collect(data_get($waiting->error_context, ThreadProgress::KEY))->pluck('id')->all())->toBe(['1', '2']);

    $this->travel(1)->hour();
    $this->artisan('posts:process-scheduled')->assertSuccessful();
    (new PublishToSocialPlatform($target->fresh()))->handle();

    $published = $target->fresh();
    expect($published->status)->toBe(PlatformStatus::Published)
        ->and($published->platform_post_id)->toBe('1')
        ->and($published->thread_reply_ids)->toEqual(['2', '3']);
    Http::assertSent(fn ($request): bool => data_get($request->data(), 'status') === 'Three' && data_get($request->data(), 'in_reply_to_id') === '2');
    expect(Http::recorded(fn ($request): bool => data_get($request->data(), 'status') === 'Root'))->toHaveCount(1)
        ->and(Http::recorded(fn ($request): bool => data_get($request->data(), 'status') === 'Two'))->toHaveCount(1);
});

test('a refused tiktok publish_id is dropped so the retry starts a new publish', function () {
    Queue::fake();
    $target = limitRetryTarget($this, Platform::TikTok, [
        'error_context' => [
            PublishCheckpoint::TIKTOK_PUBLISH_ID => 'pub_dead',
            PublishCheckpoint::TIKTOK_STATUS => 'FAILED',
        ],
    ]);
    limitRetryPublisherThrows(TikTokPublisher::class, TikTokPublishException::fromFailReason('spam_risk_too_many_posts'));

    (new PublishToSocialPlatform($target))->handle();

    expect($target->fresh()->status)->toBe(PlatformStatus::Retrying)
        ->and(PublishCheckpoint::tiktokPublishId($target->fresh()->error_context))->toBeNull()
        ->and(data_get($target->fresh()->error_context, PublishCheckpoint::TIKTOK_STATUS))->toBeNull();
});

test('a limit wait drops the x media checkpoint so the retry uploads the media again', function () {
    Queue::fake();
    $threadProgress = [['id' => '1', 'hash' => 'root-hash']];
    $target = limitRetryTarget($this, Platform::X, [
        'error_context' => [
            PublishCheckpoint::X_MEDIA => ['media-1' => '1880000000000000000'],
            ThreadProgress::KEY => $threadProgress,
        ],
    ]);
    limitRetryPublisherThrows(XPublisher::class, XPublishException::fromApiResponse(limitRetryResponse(429, ['type' => 'https://api.twitter.com/2/problems/usage-capped', 'title' => 'Usage capped'])));

    (new PublishToSocialPlatform($target))->handle();

    $context = $target->fresh()->error_context;
    expect($target->fresh()->status)->toBe(PlatformStatus::Retrying)
        ->and($context)->not->toHaveKey(PublishCheckpoint::X_MEDIA)
        ->and(PublishCheckpoint::xMedia($context))->toBe([])
        ->and(data_get($context, ThreadProgress::KEY))->toEqual($threadProgress)
        ->and(data_get($context, LimitRetryPolicy::ATTEMPTS_KEY))->toBe(1);
});

test('the scheduler unique attempts never collide with platform-unavailable attempts', function () {
    $largestProcessingBudget = max(
        (new ReflectionClassConstant(InstagramPublisher::class, 'STATUS_MAX_RETRIES'))->getValue(),
        (new ReflectionClassConstant(TikTokPublisher::class, 'STATUS_MAX_RETRIES'))->getValue(),
        (new ReflectionClassConstant(XPublisher::class, 'MEDIA_PROCESSING_MAX_RETRIES'))->getValue(),
    );

    expect(LimitRetryPolicy::UNIQUE_ATTEMPT_OFFSET)
        ->toBeGreaterThan($largestProcessingBudget + PublishToSocialPlatform::MAX_PLATFORM_UNAVAILABLE_RETRIES);
});
