<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\Post\Origin;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BackfillAccountPublications;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Analytics\CollectPublicationMetrics;
use App\Jobs\Analytics\DiscoverAccountPublications;
use App\Jobs\Analytics\ScheduleInstagramStoryMetrics;
use App\Jobs\Post\ImportExternalPostMedia;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    Bus::fake([
        BackfillAccountPublications::class,
        DiscoverAccountPublications::class,
        CollectPublicationMetrics::class,
        ImportExternalPostMedia::class,
        ScheduleInstagramStoryMetrics::class,
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
    config()->set('trypost.external_posts.import_limit', 50);
});

/**
 * @param  list<array<string, mixed>>  $media
 * @param  list<array<string, mixed>>  $stories
 */
function fakeInstagramHistory(SocialAccount $account, array $media, array $stories): void
{
    $graph = $account->platform->instagramGraphBaseUrl();

    Http::fake([
        "{$graph}/{$account->platform_user_id}/media*" => Http::response(['data' => $media]),
        "{$graph}/{$account->platform_user_id}/stories*" => Http::response(['data' => $stories]),
        "{$graph}/story-1*" => Http::response(['id' => 'story-1', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example.test/story-1.png']),
        'https://cdn.example.test/story-1.png' => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png'))),
    ]);
}

/** @return array<string, mixed> */
function liveStory(string $id, CarbonImmutable $publishedAt): array
{
    return [
        'id' => $id,
        'media_type' => 'IMAGE',
        'media_product_type' => 'STORY',
        'media_url' => "https://cdn.example.test/{$id}.png",
        'permalink' => "https://www.instagram.com/stories/acme/{$id}/",
        'timestamp' => $publishedAt->format('Y-m-d\TH:i:sO'),
    ];
}

function storyBackfillState(SocialAccount $account): AnalyticsSyncState
{
    return AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationBackfill,
        'checkpoint' => ['cursor' => null, 'revision' => 0],
        'target_since' => now()->subDays(365),
    ]);
}

function storyDiscoveryState(SocialAccount $account, CarbonImmutable $highWatermark): AnalyticsSyncState
{
    AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationBackfill,
        'status' => SyncStatus::Complete,
    ]);

    return AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationDiscovery,
        'status' => SyncStatus::Complete,
        'high_watermark_at' => $highWatermark,
    ]);
}

function runStoryDiscovery(SocialAccount $account, AnalyticsSyncState $state): void
{
    app()->call([new DiscoverAccountPublications($account->id, $state->id), 'handle']);
}

test('backfill on connect imports live stories as story posts next to feed posts and reels', function (Platform $platform) {
    Storage::fake();
    fakePublicDns();
    $account = SocialAccount::factory()->create(['platform' => $platform]);
    fakeInstagramHistory($account, [
        ['id' => 'feed-1', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED', 'caption' => 'Feed', 'timestamp' => '2026-09-28T12:00:00+0000', 'media_url' => 'https://cdn.example.test/feed.png'],
        ['id' => 'reel-1', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS', 'caption' => 'Reel', 'timestamp' => '2026-09-27T12:00:00+0000'],
    ], [liveStory('story-1', now()->subHours(2))]);
    $state = storyBackfillState($account);

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    $types = PostPlatform::query()->pluck('content_type', 'platform_post_id')->all();
    $story = Post::query()->imported()->whereRelation('postPlatforms', 'platform_post_id', 'story-1')->sole();

    expect($types)->toEqual([
        'feed-1' => ContentType::InstagramFeed,
        'reel-1' => ContentType::InstagramReel,
        'story-1' => ContentType::InstagramStory,
    ])
        ->and($story->origin)->toBe(Origin::Network)
        ->and($story->status)->toBe(PostStatus::Published)
        ->and(AnalyticsPublication::query()->where('remote_id', 'story-1')->sole()->content_type)->toBe(PublicationContentType::Story);
    Bus::assertDispatched(ImportExternalPostMedia::class, fn (ImportExternalPostMedia $job): bool => $job->postId === $story->id);
    Bus::assertDispatched(ScheduleInstagramStoryMetrics::class, 1);

    app()->call([new ImportExternalPostMedia($story->id), 'handle']);

    expect(collect($story->fresh()->media)->pluck('original_filename')->all())->toBe(['story-1.png']);
})->with([
    'instagram login' => Platform::Instagram,
    'facebook login' => Platform::InstagramFacebook,
]);

test('stories are listed once per run, on the first page only', function () {
    $account = SocialAccount::factory()->instagram()->create();
    fakeInstagramHistory($account, [], [liveStory('story-1', now()->subHour())]);
    $state = storyBackfillState($account);
    $state->update(['checkpoint' => ['cursor' => 'page-2', 'revision' => 0]]);

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/stories'));
    expect(AnalyticsPublication::query()->count())->toBe(0);
});

test('discovery picks up a new story even when it is older than the watermark overlap', function () {
    config()->set('trypost.analytics.discovery_overlap_hours', 6);
    $account = SocialAccount::factory()->instagram()->create();
    fakeInstagramHistory($account, [], [liveStory('story-1', now()->subHours(20))]);
    $state = storyDiscoveryState($account, now()->subHour()->toImmutable());

    runStoryDiscovery($account, $state);

    expect(PostPlatform::query()->sole())
        ->platform_post_id->toBe('story-1')
        ->content_type->toBe(ContentType::InstagramStory);
});

test('a story seen on every discovery run is imported once', function () {
    $account = SocialAccount::factory()->instagram()->create();
    fakeInstagramHistory($account, [], [liveStory('story-1', now()->subHours(2))]);
    $state = storyDiscoveryState($account, now()->subHours(3)->toImmutable());

    runStoryDiscovery($account, $state);
    runStoryDiscovery($account, $state->fresh());

    expect(Post::query()->imported()->count())->toBe(1)
        ->and(AnalyticsPublication::query()->count())->toBe(1);
});

test('a story trypost published is linked instead of imported again', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $post = Post::factory()->create([
        'workspace_id' => $account->workspace_id,
        'status' => PostStatus::Published,
        'origin' => Origin::TryPost,
        'published_at' => now()->subHours(2),
    ]);
    $tryPost = PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'content_type' => ContentType::InstagramStory,
        'platform_post_id' => 'story-1',
        'published_at' => now()->subHours(2),
    ]);
    fakeInstagramHistory($account, [], [liveStory('story-1', now()->subHours(2))]);
    $state = storyDiscoveryState($account, now()->subHours(3)->toImmutable());

    runStoryDiscovery($account, $state);

    expect(Post::query()->imported()->exists())->toBeFalse()
        ->and(AnalyticsPublication::query()->sole()->post_platform_id)->toBe($tryPost->id);
});

test('story metrics are scheduled once however many runs see the story', function () {
    $account = SocialAccount::factory()->instagram()->create();
    fakeInstagramHistory($account, [], [liveStory('story-1', now()->subHours(2))]);
    $state = storyDiscoveryState($account, now()->subHours(3)->toImmutable());
    runStoryDiscovery($account, $state);
    $publicationId = AnalyticsPublication::query()->sole()->id;

    app()->call([new ScheduleInstagramStoryMetrics($publicationId), 'handle']);
    app()->call([new ScheduleInstagramStoryMetrics($publicationId), 'handle']);

    Bus::assertDispatched(CollectPublicationMetrics::class, fn (CollectPublicationMetrics $job): bool => $job->publicationId === $publicationId && $job->refreshSameDay);
    Bus::assertDispatchedTimes(CollectPublicationMetrics::class, 3);
});

test('a stories permission error does not stop the feed page', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $graph = $account->platform->instagramGraphBaseUrl();
    Http::fake([
        "{$graph}/{$account->platform_user_id}/media*" => Http::response(['data' => [
            ['id' => 'feed-1', 'media_type' => 'IMAGE', 'timestamp' => '2026-09-28T12:00:00+0000'],
        ]]),
        "{$graph}/{$account->platform_user_id}/stories*" => Http::response(['error' => ['code' => 10, 'message' => 'Permission denied']], 403),
    ]);
    $state = storyBackfillState($account);

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    expect(PostPlatform::query()->sole()->platform_post_id)->toBe('feed-1')
        ->and($state->fresh()->status)->toBe(SyncStatus::Complete);
});
