<?php

declare(strict_types=1);

use App\Actions\Analytics\UpsertAnalyticsPublication;
use App\Actions\Post\ImportExternalPosts;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Enums\TikTok\PrivacyLevel;
use App\Exceptions\PlatformUnavailableException;
use App\Jobs\PublishPost;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\FacebookPublicationCollector;
use App\Services\Analytics\Collectors\Publications\InstagramPublicationCollector;
use App\Services\Analytics\Collectors\Publications\ThreadsPublicationCollector;
use App\Services\Social\BlueskyPublisher;
use App\Services\Social\Discord\DiscordPublisher;
use App\Services\Social\FacebookPublisher;
use App\Services\Social\GoogleBusinessPublisher;
use App\Services\Social\InstagramPublisher;
use App\Services\Social\LinkedInPagePublisher;
use App\Services\Social\LinkedInPublisher;
use App\Services\Social\MastodonPublisher;
use App\Services\Social\PinterestPublisher;
use App\Services\Social\Telegram\TelegramPublisher;
use App\Services\Social\ThreadsPublisher;
use App\Services\Social\TikTokPublisher;
use App\Services\Social\XPublisher;
use App\Services\Social\YouTubePublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->freezeTime();
    Storage::fake();
    Exceptions::fake();
    config()->set('queue.default', 'database');

    // Exercise serialization, queue middleware and the worker on the test database.
    Queue::swap(Queue::getFacadeRoot()->queue);
    $this->publishingQueue = Queue::connection('database');
    $this->publishingWorker = app('queue.worker');
    Bus::fake()->except([PublishPost::class, PublishToSocialPlatform::class]);
    $this->workerOptions = new WorkerOptions(sleep: 0, maxTries: 1);
});

function scheduledPipelineTarget(Platform $platform): PostPlatform
{
    $account = SocialAccount::factory()->create([
        'platform' => $platform,
        'scopes' => $platform->requiredPublishScopes(),
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $account->workspace_id,
        'user_id' => $account->workspace->user_id,
        'scheduled_at' => now()->subMinute(),
        'content' => 'Publishing pipeline regression',
    ]);
    $contentType = ContentType::defaultFor($platform);

    if ($contentType->requiresMedia()) {
        $factory = Media::factory()->ownedByPost($post)->stored();
        if (in_array($platform, [Platform::TikTok, Platform::YouTube], true)) {
            $factory = $factory->video();
        }
        $media = $factory->create(['meta' => ['width' => 1080, 'height' => 1080, 'duration' => 15]]);
        $post->update(['media' => [$media->toArray()]]);
    }

    return PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $platform,
        'content_type' => $contentType,
        'meta' => $platform === Platform::TikTok ? ['privacy_level' => PrivacyLevel::SelfOnly->value] : [],
    ]);
}

test('a published facebook photo is not imported again when publishing returned only the photo id', function () {
    $target = scheduledPipelineTarget(Platform::Facebook);
    $account = $target->socialAccount;
    $account->update(['scopes' => [...$account->scopes, 'pages_read_engagement']]);
    $media = Media::factory()->ownedByPost($target->post)->stored()->create(['size' => 100000]);
    $target->post->update(['media' => [$media->toArray()]]);
    $base = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$base}/{$account->platform_user_id}/photos" => Http::response(['id' => 'photo-123']),
        "{$base}/{$account->platform_user_id}/published_posts*" => Http::response(['data' => [[
            'id' => 'page_post-123',
            'status_type' => 'added_photos',
            'message' => $target->post->content,
            'created_time' => now()->toIso8601String(),
            'attachments' => ['data' => [[
                'type' => 'photo',
                'media_type' => 'photo',
                'target' => ['id' => 'photo-123'],
            ]]],
        ]]]),
    ]);

    (new PublishToSocialPlatform($target))->handle();

    expect($target->refresh()->status)->toBe(PlatformStatus::Published)
        ->and($target->platform_post_id)->toBe('photo-123');

    $page = app(FacebookPublicationCollector::class)->page($account, null, now()->subDay()->toImmutable());
    app(UpsertAnalyticsPublication::class)->external($account, $page->publications[0]);

    expect(ImportExternalPosts::execute($account))->toBe([])
        ->and(Post::query()->imported()->count())->toBe(0)
        ->and($target->post->refresh()->status)->toBe(PostStatus::Published);
    Http::assertSentCount(2);
    Exceptions::assertNothingReported();
});

test('a published threads post keeps its identity through a failed permalink lookup and later import', function () {
    $target = scheduledPipelineTarget(Platform::Threads);
    $account = $target->socialAccount;
    $account->update(['scopes' => [...$account->scopes, 'threads_basic']]);
    $base = config('trypost.platforms.threads.graph_api');

    Http::fake([
        "{$base}/{$account->platform_user_id}/threads" => Http::response(['id' => 'container-123']),
        "{$base}/container-123*" => Http::response(['status' => 'FINISHED']),
        "{$base}/{$account->platform_user_id}/threads_publish" => Http::response(['id' => 'post-123']),
        "{$base}/post-123*" => Http::failedConnection('Permalink connection lost'),
        "{$base}/{$account->platform_user_id}/threads?*" => Http::response(['data' => [[
            'id' => 'post-123',
            'media_type' => 'TEXT_POST',
            'text' => $target->post->content,
            'timestamp' => now()->toIso8601String(),
        ]]]),
    ]);

    (new PublishToSocialPlatform($target))->handle();

    expect($target->refresh()->status)->toBe(PlatformStatus::Published)
        ->and($target->platform_post_id)->toBe('post-123')
        ->and($target->platform_url)->toBeNull();

    $page = app(ThreadsPublicationCollector::class)->page($account, null, now()->subDay()->toImmutable());
    app(UpsertAnalyticsPublication::class)->external($account, $page->publications[0]);

    expect(ImportExternalPosts::execute($account))->toBe([])
        ->and(Post::query()->imported()->count())->toBe(0)
        ->and($target->post->refresh()->status)->toBe(PostStatus::Published);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
        && Str::endsWith($request->url(), '/threads_publish')))->toHaveCount(1);
    Exceptions::assertNothingReported();
});

test('instagram does not duplicate a captionless publication after losing its media id', function (Platform $platform, ContentType $contentType) {
    $target = scheduledPipelineTarget($platform);
    $target->post->update(['content' => '']);
    $target->update([
        'content_type' => $contentType,
        'error_context' => ['instagram_workflow' => [
            'stage' => 'final_container',
            'container_id' => 'container-123',
        ]],
    ]);
    $account = $target->socialAccount;
    $account->update(['scopes' => [...$account->scopes, 'instagram_basic', 'instagram_business_basic']]);
    $base = $platform->instagramGraphBaseUrl();
    $story = $contentType === ContentType::InstagramStory;
    $discovered = [[
        'id' => 'media-123',
        'media_type' => 'IMAGE',
        'timestamp' => now()->toIso8601String(),
    ]];

    Http::fake([
        "{$base}/container-123*" => Http::sequence()
            ->push(['status_code' => 'FINISHED'])
            ->push(['status_code' => 'PUBLISHED']),
        "{$base}/{$account->platform_user_id}/media_publish" => Http::failedConnection('Publish response lost'),
        "{$base}/{$account->platform_user_id}/media?*" => Http::response(['data' => $story ? [] : $discovered]),
        "{$base}/{$account->platform_user_id}/stories?*" => Http::response(['data' => $story ? $discovered : []]),
    ]);

    (new PublishToSocialPlatform($target))->handle();
    expect($target->refresh()->status)->toBe(PlatformStatus::Retrying);

    $page = app(InstagramPublicationCollector::class)->page($account, null, now()->subDay()->toImmutable());
    expect($page->publications)->toHaveCount(1);
    $publication = app(UpsertAnalyticsPublication::class)->external($account, $page->publications[0]);

    expect(ImportExternalPosts::execute($account))->toBe([])
        ->and($publication->refresh()->post_platform_id)->toBeNull();

    $this->travel(61)->seconds();
    (new PublishToSocialPlatform($target))->handle();
    expect($target->refresh()->status)->toBe(PlatformStatus::Published)
        ->and($target->platform_post_id)->toBe('container-123');

    expect(ImportExternalPosts::execute($account))->toBe([])
        ->and(Post::query()->imported()->count())->toBe(0)
        ->and($publication->refresh()->post_platform_id)->toBeNull()
        ->and($target->refresh()->platform_post_id)->toBe('container-123');
})->with([
    'Instagram feed' => [Platform::Instagram, ContentType::InstagramFeed],
    'Instagram story' => [Platform::Instagram, ContentType::InstagramStory],
    'Instagram Facebook feed' => [Platform::InstagramFacebook, ContentType::InstagramFeed],
    'Instagram Facebook story' => [Platform::InstagramFacebook, ContentType::InstagramStory],
]);

test('each network queue consumes scheduled posts and delayed retries through the worker', function (Platform $platform, string $publisherClass) {
    $retrying = scheduledPipelineTarget($platform);
    $broken = scheduledPipelineTarget($platform);
    $retryCalls = 0;
    $publisher = Mockery::mock($publisherClass);
    $publisher->shouldReceive('publish')->times(4)->andReturnUsing(function (PostPlatform $target) use ($retrying, $broken, &$retryCalls): array {
        if ($target->is($retrying) && ++$retryCalls === 1) {
            throw new PlatformUnavailableException('Temporary outage');
        }
        if ($target->is($broken)) {
            throw new RuntimeException('Unexpected publisher failure');
        }

        return ['id' => 'published-'.$target->id, 'url' => 'https://example.com/published'];
    });
    $this->app->instance($publisherClass, $publisher);

    expect(config('horizon.defaults.supervisor-1.queue'))->toContain('default')
        ->and(config('horizon.defaults.social-publishing.queue'))->toContain($platform->queue());

    $this->artisan('posts:process-scheduled')->assertSuccessful();
    expect($this->publishingQueue->size('default'))->toBe(2);

    for ($i = 0; $i < 2; $i++) {
        $this->publishingWorker->runNextJob('database', 'default', $this->workerOptions);
    }

    expect($this->publishingQueue->size('default'))->toBe(0)
        ->and($this->publishingQueue->size($platform->queue()))->toBe(2);

    for ($i = 0; $i < 2; $i++) {
        $this->publishingWorker->runNextJob('database', $platform->queue(), $this->workerOptions);
    }

    expect($retrying->refresh()->status)->toBe(PlatformStatus::Retrying)
        ->and($broken->refresh()->status)->toBe(PlatformStatus::Failed)
        ->and($this->publishingQueue->pendingSize($platform->queue()))->toBe(0)
        ->and($this->publishingQueue->delayedSize($platform->queue()))->toBe(1);

    $healthy = scheduledPipelineTarget($platform);
    $this->artisan('posts:process-scheduled')->assertSuccessful();
    $this->publishingWorker->runNextJob('database', 'default', $this->workerOptions);
    $this->publishingWorker->runNextJob('database', $platform->queue(), $this->workerOptions);

    expect($healthy->refresh()->status)->toBe(PlatformStatus::Published)
        ->and($healthy->post->status)->toBe(PostStatus::Published);

    $this->travel(10)->minutes();
    $this->publishingWorker->runNextJob('database', $platform->queue(), $this->workerOptions);

    expect($retrying->refresh()->status)->toBe(PlatformStatus::Published)
        ->and($retrying->post->status)->toBe(PostStatus::Published)
        ->and($this->publishingQueue->size($platform->queue()))->toBe(0);
    $this->assertDatabaseCount('failed_jobs', 0);
})->with([
    'LinkedIn' => [Platform::LinkedIn, LinkedInPublisher::class],
    'LinkedIn pages' => [Platform::LinkedInPage, LinkedInPagePublisher::class],
    'X' => [Platform::X, XPublisher::class],
    'TikTok' => [Platform::TikTok, TikTokPublisher::class],
    'YouTube' => [Platform::YouTube, YouTubePublisher::class],
    'Facebook' => [Platform::Facebook, FacebookPublisher::class],
    'Instagram' => [Platform::Instagram, InstagramPublisher::class],
    'Instagram via Facebook' => [Platform::InstagramFacebook, InstagramPublisher::class],
    'Threads' => [Platform::Threads, ThreadsPublisher::class],
    'Pinterest' => [Platform::Pinterest, PinterestPublisher::class],
    'Bluesky' => [Platform::Bluesky, BlueskyPublisher::class],
    'Mastodon' => [Platform::Mastodon, MastodonPublisher::class],
    'Telegram' => [Platform::Telegram, TelegramPublisher::class],
    'Discord' => [Platform::Discord, DiscordPublisher::class],
    'Google Business' => [Platform::GoogleBusiness, GoogleBusinessPublisher::class],
]);

test('the TikTok worker publishes through the real publisher and token refresh flow', function (string $scenario) {
    $affected = scheduledPipelineTarget(Platform::TikTok);
    $affected->socialAccount->update(['token_expires_at' => now()->subMinute(), 'refresh_token' => 'affected-refresh']);
    $refreshCalls = 0;

    Http::fake(function (Request $request) use ($scenario, &$refreshCalls) {
        if (str_ends_with($request->url(), '/oauth/token/')) {
            $refreshCalls++;
            if ($scenario === 'dead refresh') {
                return Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token revoked']);
            }
            if ($scenario === 'temporary refresh' && $refreshCalls === 1) {
                return Http::response(['error' => 'server_error'], 503);
            }

            return Http::response(['access_token' => 'renewed-token', 'refresh_token' => 'rotated-refresh', 'expires_in' => 86400]);
        }
        if (str_ends_with($request->url(), '/post/publish/video/init/')) {
            return Http::response(['data' => ['publish_id' => $request->hasHeader('Authorization', 'Bearer renewed-token') ? 'affected-publish' : 'healthy-publish']]);
        }
        if (str_ends_with($request->url(), '/post/publish/status/fetch/')) {
            return Http::response(['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => [$request['publish_id'].'-public']]]);
        }

        throw new RuntimeException('Unexpected TikTok request: '.$request->url());
    });

    $this->artisan('posts:process-scheduled')->assertSuccessful();
    $this->publishingWorker->runNextJob('database', 'default', $this->workerOptions);
    $this->publishingWorker->runNextJob('database', Platform::TikTok->queue(), $this->workerOptions);

    $healthy = scheduledPipelineTarget(Platform::TikTok);
    $this->artisan('posts:process-scheduled')->assertSuccessful();
    $this->publishingWorker->runNextJob('database', 'default', $this->workerOptions);
    $this->publishingWorker->runNextJob('database', Platform::TikTok->queue(), $this->workerOptions);

    expect($healthy->refresh()->status)->toBe(PlatformStatus::Published)
        ->and($healthy->socialAccount->status)->toBe(AccountStatus::Connected)
        ->and($healthy->post->status)->toBe(PostStatus::Published);

    if ($scenario === 'temporary refresh') {
        expect($affected->refresh()->status)->toBe(PlatformStatus::Retrying)
            ->and($affected->socialAccount->status)->toBe(AccountStatus::Connected);
        $this->travel(10)->minutes();
        $this->publishingWorker->runNextJob('database', Platform::TikTok->queue(), $this->workerOptions);
    }

    expect($affected->refresh()->status)->toBe($scenario === 'dead refresh' ? PlatformStatus::Failed : PlatformStatus::Published)
        ->and($affected->socialAccount->status)->toBe($scenario === 'dead refresh' ? AccountStatus::TokenExpired : AccountStatus::Connected)
        ->and($this->publishingQueue->size(Platform::TikTok->queue()))->toBe(0);
    expect(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/post/publish/video/init/')))
        ->toHaveCount($scenario === 'dead refresh' ? 1 : 2);
    $this->assertDatabaseCount('failed_jobs', 0);
})->with(['successful refresh', 'dead refresh', 'temporary refresh']);
