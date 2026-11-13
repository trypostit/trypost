<?php

declare(strict_types=1);

use App\Actions\Analytics\SyncTryPostPublication;
use App\Actions\Analytics\UpsertAnalyticsPublication;
use App\Actions\Post\ImportExternalPosts;
use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\MetricValue;
use App\Enums\Analytics\ExposureKind;
use App\Enums\Analytics\MetricAvailability;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricPrecision;
use App\Enums\Analytics\MetricTimeBasis;
use App\Enums\Analytics\MetricUnit;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\Post\PublishStatus as Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Events\PostDeleted;
use App\Jobs\Analytics\SyncTryPostPublication as SyncTryPostPublicationJob;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
});

test('external discovery followed by TryPost sync converges on one TryPost publication', function () {
    [$account, $post] = publicationFixture('remote-1');
    $upsert = app(UpsertAnalyticsPublication::class);

    $upsert->external($account, discoveredPublication('remote-1'));
    app(SyncTryPostPublication::class)->handle($post);

    $publication = AnalyticsPublication::sole();
    expect($publication->origin)->toBe(PublicationOrigin::TryPost)
        ->and($publication->post_id)->toBe($post->id)
        ->and($publication->provider_published_at?->toISOString())->toBe('2026-09-20T12:00:00.000000Z');
});

test('TryPost sync followed by external discovery preserves TryPost ownership', function () {
    [$account, $post] = publicationFixture('remote-1');

    app(SyncTryPostPublication::class)->handle($post);
    app(UpsertAnalyticsPublication::class)->external($account, discoveredPublication(
        providerPostId: 'remote-1',
        excerpt: 'Provider copy',
    ));

    $publication = AnalyticsPublication::sole();
    expect($publication->origin)->toBe(PublicationOrigin::TryPost)
        ->and($publication->post_id)->toBe($post->id)
        ->and($publication->excerpt)->toBe('Provider copy');
});

test('duplicate provider pages are idempotent', function () {
    [$account] = publicationFixture();
    $upsert = app(UpsertAnalyticsPublication::class);

    $upsert->external($account, discoveredPublication('remote-1'));
    $upsert->external($account, discoveredPublication('remote-1'));

    expect(AnalyticsPublication::count())->toBe(1);
});

test('TikTok public video id merges a provisional TryPost post with earlier discovery and snapshots', function () {
    $account = SocialAccount::factory()->create(['platform' => Platform::TikTok]);
    $post = Post::factory()->forAccount($account, ContentType::TikTokVideo)->published()->create([
        'platform_post_id' => 'v_pub_provisional',
    ]);
    $provisional = app(SyncTryPostPublication::class)->handle($post);
    $discovered = app(UpsertAnalyticsPublication::class)->external($account, new DiscoveredPublication(
        providerPostId: '123456789',
        publishedAt: CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
        contentType: PublicationContentType::Video,
        permalink: 'https://www.tiktok.com/@example/video/123456789',
    ));
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $provisional->id,
        'date' => '2026-09-21',
        'collected_at' => '2026-09-21 10:00:00',
        'metrics' => storedMetrics(['views' => 5]),
        'views_count' => 5,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $discovered->id,
        'date' => '2026-09-21',
        'collected_at' => '2026-09-21 12:00:00',
        'metrics' => storedMetrics(['views' => 12]),
        'views_count' => 12,
    ]);

    app(UpsertAnalyticsPublication::class)->reconcileRemoteId($provisional, '123456789');

    expect(AnalyticsPublication::query()->count())->toBe(1)
        ->and($provisional->fresh()->remote_id)->toBe('123456789')
        ->and($provisional->fresh()->origin)->toBe(PublicationOrigin::TryPost)
        ->and(AnalyticsPublicationDailySnapshot::query()->count())->toBe(1)
        ->and($provisional->dailySnapshots()->sole()->views_count)->toBe(12)
        ->and($post->fresh()->platform_post_id)->toBe('123456789');

    app(UpsertAnalyticsPublication::class)->external($account, new DiscoveredPublication(
        providerPostId: '123456789',
        publishedAt: CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
        contentType: PublicationContentType::Video,
    ));
    expect(AnalyticsPublication::query()->count())->toBe(1);
});

test('TikTok public video id replaces a post imported from that video with the TryPost post', function () {
    Event::fake([PostDeleted::class]);
    $account = SocialAccount::factory()->create(['platform' => Platform::TikTok]);
    $post = Post::factory()->forAccount($account, ContentType::TikTokVideo)->published()->create([
        'platform_post_id' => 'v_pub_provisional',
    ]);
    $provisional = app(SyncTryPostPublication::class)->handle($post);
    $discovered = app(UpsertAnalyticsPublication::class)->external($account, new DiscoveredPublication(
        providerPostId: '123456789',
        publishedAt: now()->subDay()->toImmutable(),
        contentType: PublicationContentType::Video,
        permalink: 'https://www.tiktok.com/@example/video/123456789',
    ));
    ImportExternalPosts::execute($account);
    $imported = Post::query()->imported()->sole();
    $media = Media::factory()->ownedByPost($imported)->create();
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $discovered->id,
        'date' => '2026-09-21',
        'collected_at' => '2026-09-21 12:00:00',
        'views_count' => 12,
    ]);

    app(UpsertAnalyticsPublication::class)->reconcileRemoteId($provisional, '123456789');

    expect(AnalyticsPublication::query()->sole()->id)->toBe($provisional->id)
        ->and($provisional->fresh()->post_id)->toBe($post->id)
        ->and($provisional->dailySnapshots()->sole()->views_count)->toBe(12)
        ->and($post->fresh()->platform_post_id)->toBe('123456789')
        ->and(Post::query()->imported()->exists())->toBeFalse()
        ->and(Post::query()->sole()->id)->toBe($post->id)
        ->and(Media::query()->whereKey($media->id)->exists())->toBeFalse();
    Event::assertNotDispatched(PostDeleted::class);

    expect(ImportExternalPosts::execute($account))->toBe([])
        ->and(Post::query()->count())->toBe(1);
});

test('reconciling a remote id keeps the reply poll results the metrics read stored, next to the discovered poll', function () {
    $account = SocialAccount::factory()->create(['platform' => Platform::X]);
    $post = Post::factory()->forAccount($account, ContentType::XPoll)->published()->create(['platform_post_id' => 'provisional']);
    $provisional = app(SyncTryPostPublication::class)->handle($post);
    $threadPolls = [['reply' => 0, 'options' => [['label' => 'Yes', 'votes' => 5]], 'voting_status' => 'open']];
    $provisional->forceFill(['provider_metadata' => ['thread_polls' => $threadPolls]])->save();
    $poll = ['options' => [['label' => 'Tabs', 'votes' => 3]], 'voting_status' => 'open'];
    app(UpsertAnalyticsPublication::class)->external($account, new DiscoveredPublication(
        providerPostId: '987654321',
        publishedAt: CarbonImmutable::now('UTC')->subDay(),
        contentType: PublicationContentType::Poll,
        providerMetadata: ['poll' => $poll],
    ));

    app(UpsertAnalyticsPublication::class)->reconcileRemoteId($provisional, '987654321');

    expect(AnalyticsPublication::query()->count())->toBe(1)
        ->and($provisional->fresh()->provider_metadata)->toEqual(['poll' => $poll, 'thread_polls' => $threadPolls]);
});

test('TikTok public id owned by another TryPost post is still refused', function () {
    $account = SocialAccount::factory()->create(['platform' => Platform::TikTok]);
    $targets = collect(['v_pub_provisional', '123456789'])->map(fn (string $remoteId): Post => Post::factory()->forAccount($account, ContentType::TikTokVideo)->published()->create([
        'platform_post_id' => $remoteId,
    ]));
    $provisional = app(SyncTryPostPublication::class)->handle($targets->first());
    app(SyncTryPostPublication::class)->handle($targets->last());

    expect(fn () => app(UpsertAnalyticsPublication::class)->reconcileRemoteId($provisional, '123456789'))
        ->toThrow(LogicException::class);
});

test('an excerpt longer than the text column is cut on a character boundary and imported', function () {
    $account = SocialAccount::factory()->x()->create();
    $excerpt = str_repeat('नम', 11100);

    $publication = app(UpsertAnalyticsPublication::class)->external($account, new DiscoveredPublication(
        providerPostId: 'long-1',
        publishedAt: now()->subDay()->toImmutable(),
        contentType: PublicationContentType::Text,
        excerpt: $excerpt,
    ));
    ImportExternalPosts::execute($account);

    $stored = $publication->fresh()->excerpt;
    expect(strlen($excerpt))->toBeGreaterThan(65535)
        ->and(strlen($stored))->toBeLessThanOrEqual(65535)
        ->and(mb_check_encoding($stored, 'UTF-8'))->toBeTrue()
        ->and(str_starts_with($excerpt, $stored))->toBeTrue()
        ->and(Post::query()->imported()->sole()->content)->toBe($stored);
});

test('database failures other than identity collisions are not swallowed', function () {
    [$account] = publicationFixture();

    expect(fn () => app(UpsertAnalyticsPublication::class)->external(
        $account,
        discoveredPublication(str_repeat('x', 192)),
    ))->toThrow(QueryException::class);
});

test('the same provider post id remains separate by account and workspace', function () {
    $workspace = Workspace::factory()->create();
    $firstAccount = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $secondAccount = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $otherAccount = SocialAccount::factory()->x()->create();
    $upsert = app(UpsertAnalyticsPublication::class);

    foreach ([$firstAccount, $secondAccount, $otherAccount] as $account) {
        $upsert->external($account, discoveredPublication('shared-provider-id'));
    }

    expect(AnalyticsPublication::count())->toBe(3)
        ->and(AnalyticsPublication::query()->where('workspace_id', $workspace->id)->count())->toBe(2);
});

test('queued TryPost sync survives social account deletion after dispatch', function () {
    [$account, $post] = publicationFixture();
    $post->forceFill(['publish_status' => Status::Pending])->save();
    $queuedJob = null;
    Queue::fake();

    $post->markPublicationPublished('remote-1', 'https://x.com/example/status/remote-1');

    Queue::assertPushed(SyncTryPostPublicationJob::class, function (SyncTryPostPublicationJob $job) use (&$queuedJob, $account, $post): bool {
        $queuedJob = $job;

        return $job->postId === $post->id
            && $job->identity->socialAccountId === $account->id
            && $job->queue === 'analytics'
            && $job->afterCommit === true;
    });

    $account->delete();
    app()->call([$queuedJob, 'handle']);

    $publication = AnalyticsPublication::sole();
    expect($publication->social_account_id)->toBeNull()
        ->and($publication->social_account_key)->toBe($account->id)
        ->and($publication->origin)->toBe(PublicationOrigin::TryPost)
        ->and($publication->post_id)->toBe($post->id);
});

/** @return array{SocialAccount, Post} */
function publicationFixture(?string $providerPostId = null): array
{
    $account = SocialAccount::factory()->x()->create();
    $post = Post::factory()->forAccount($account)->published()->create([
        'content' => '<p>TryPost copy</p>',
        'platform_post_id' => $providerPostId ?? 'remote-1',
        'platform_url' => 'https://x.com/example/status/remote-1',
        'published_at' => CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
    ]);

    return [$account, $post];
}

function discoveredPublication(
    string $providerPostId = 'remote-1',
    string $excerpt = 'External copy',
): DiscoveredPublication {
    return new DiscoveredPublication(
        providerPostId: $providerPostId,
        publishedAt: CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
        contentType: PublicationContentType::Text,
        providerContentType: 'tweet',
        permalink: "https://x.com/example/status/{$providerPostId}",
        excerpt: $excerpt,
        previewMetadata: ['thumbnail_url' => 'https://example.com/thumb.jpg'],
        providerMetadata: ['source' => 'provider'],
    );
}

test('a less-than sign typed in a TryPost post stays in its analytics excerpt', function () {
    [, $post] = publicationFixture('remote-lt');
    $post->update(['content' => 'I <3 TryPost and price < 10']);

    expect(app(SyncTryPostPublication::class)->handle($post->fresh())->excerpt)->toBe('I <3 TryPost and price < 10');
});

test('a network that does not report the type keeps the type TryPost published', function (Platform $platform, ContentType $contentType, PublicationContentType $expected) {
    $account = SocialAccount::factory()->create(['platform' => $platform]);
    $post = Post::factory()->forAccount($account, $contentType)->published()->create(['platform_post_id' => 'remote-1']);
    app(SyncTryPostPublication::class)->handle($post);

    app(UpsertAnalyticsPublication::class)->external($account, new DiscoveredPublication(
        providerPostId: 'remote-1',
        publishedAt: CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
        contentType: PublicationContentType::Video,
        contentTypeReported: false,
    ));

    expect(AnalyticsPublication::sole()->content_type)->toBe($expected);
})->with([
    'TikTok photo' => [Platform::TikTok, ContentType::TikTokPhoto, PublicationContentType::Carousel],
    'YouTube Short' => [Platform::YouTube, ContentType::YouTubeShort, PublicationContentType::Short],
    'X poll' => [Platform::X, ContentType::XPoll, PublicationContentType::Poll],
]);

test('a network that does not report the type stores its default on a new publication', function () {
    $account = SocialAccount::factory()->create(['platform' => Platform::TikTok]);

    app(UpsertAnalyticsPublication::class)->external($account, new DiscoveredPublication(
        providerPostId: 'remote-1',
        publishedAt: CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
        contentType: PublicationContentType::Video,
        contentTypeReported: false,
    ));

    expect(AnalyticsPublication::sole()->content_type)->toBe(PublicationContentType::Video);
});

test('a network that reports the type still corrects the type TryPost published', function () {
    $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);
    $post = Post::factory()->forAccount($account, ContentType::InstagramFeed)->published()->create([
        'platform_post_id' => 'remote-1',
        'media' => [['id' => 'video', 'path' => 'medias/a.mp4', 'url' => 'https://cdn.trypost.test/medias/a.mp4', 'type' => 'video', 'mime_type' => 'video/mp4']],
    ]);
    app(SyncTryPostPublication::class)->handle($post);

    app(UpsertAnalyticsPublication::class)->external($account, new DiscoveredPublication(
        providerPostId: 'remote-1',
        publishedAt: CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
        contentType: PublicationContentType::Reel,
    ));

    expect(AnalyticsPublication::sole()->content_type)->toBe(PublicationContentType::Reel);
});

/**
 * @param  array<string, int|null>  $counts
 * @return array<string, array<string, mixed>>
 */
function storedMetrics(array $counts): array
{
    return collect($counts)->mapWithKeys(fn (?int $value, string $key): array => [$key => (new MetricValue(
        key: MetricKey::from($key),
        value: $value,
        unit: MetricUnit::Count,
        timeBasis: MetricTimeBasis::Lifetime,
        precision: MetricPrecision::Exact,
        availability: filled($value) ? MetricAvailability::Available : MetricAvailability::Unavailable,
    ))->toArray()])->all();
}

/**
 * @param  array<string, int|null>  $trackedCounts
 * @param  array<string, int|null>  $discoveredCounts
 * @return array{0: AnalyticsPublication, 1: Post}
 */
function facebookVideoReadTwiceOnOneDay(array $trackedCounts, array $discoveredCounts, int $discoveredHoursLater): array
{
    $account = SocialAccount::factory()->facebook()->create();
    $post = Post::factory()->forAccount($account, ContentType::FacebookReel)->published()->create(['platform_post_id' => 'video-9']);
    $tracked = app(SyncTryPostPublication::class)->handle($post);
    $discovered = app(UpsertAnalyticsPublication::class)->external($account, new DiscoveredPublication(
        providerPostId: 'page_post-1',
        publishedAt: now()->subDay()->toImmutable(),
        contentType: PublicationContentType::Reel,
        providerMetadata: ['video_id' => 'video-9'],
    ));
    $collectedAt = now()->subHours(3);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $tracked->id,
        'date' => $collectedAt->toDateString(),
        'collected_at' => $collectedAt->addHour(),
        'metrics' => storedMetrics($trackedCounts),
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $discovered->id,
        'date' => $collectedAt->toDateString(),
        'collected_at' => $collectedAt->addHour()->addHours($discoveredHoursLater),
        'metrics' => storedMetrics($discoveredCounts),
    ]);

    return [$tracked, $post];
}

test('merging two reads of the same day follows the same-day rule of every read', function (array $tracked, array $discovered, int $discoveredHoursLater, array $expected) {
    [$publication, $post] = facebookVideoReadTwiceOnOneDay($tracked, $discovered, $discoveredHoursLater);

    expect(app(UpsertAnalyticsPublication::class)->mergeDiscovery($publication, 'page_post-1'))->toBeTrue();

    $snapshot = $publication->dailySnapshots()->sole();

    expect($snapshot->only(array_keys($expected)))->toEqual($expected)
        ->and($publication->fresh()->remote_id)->toBe('page_post-1')
        ->and($post->fresh()->platform_post_id)->toBe('video-9');
})->with([
    'the older read measured comments the newer did not' => [
        ['views' => 52, 'comments' => null], ['views' => 50, 'comments' => 3], -1,
        ['views_count' => 52, 'comments_count' => 3],
    ],
    'a newer read never lowers a lifetime count' => [
        ['reactions' => 12, 'comments' => 3], ['reactions' => 5, 'comments' => null], 1,
        ['reactions_count' => 12, 'comments_count' => 3],
    ],
    'a newer read raises a lifetime count' => [
        ['views' => 50], ['views' => 58], 1,
        ['views_count' => 58],
    ],
    'engagement and exposure come from the merged metrics' => [
        ['views' => 52, 'engagements' => 5], ['reach' => 40, 'engagements' => 16], -1,
        ['engagement_count' => 16, 'exposure_count' => 40, 'exposure_kind' => ExposureKind::Reach],
    ],
]);

test('a stored metric this version cannot read back is kept, not lost or fatal', function () {
    [$publication] = facebookVideoReadTwiceOnOneDay(['views' => 52], ['views' => 50], 1);
    $legacy = ['value' => 7, 'unit' => 'count', 'time_basis' => 'lifetime', 'precision' => 'exact', 'availability' => 'available'];
    $discoveredSnapshot = AnalyticsPublicationDailySnapshot::query()->whereNot('publication_id', $publication->id)->sole();
    $badProviderMetric = [...$legacy, 'provider_metric' => 12];
    $badPeriod = [...$legacy, 'period_start' => 'not a date'];
    $badUnit = [...$legacy, 'unit' => ['count']];
    $discoveredSnapshot->forceFill(['metrics' => [
        ...$discoveredSnapshot->metrics,
        'a_retired_metric' => $legacy,
        'reactions' => ['value' => 3],
        'views' => ['value' => 99],
        'shares' => $badProviderMetric,
        'clicks' => $badPeriod,
        'saves' => $badUnit,
        'reach' => [...$legacy, 'period_start' => ''],
    ]])->save();

    expect(app(UpsertAnalyticsPublication::class)->mergeDiscovery($publication, 'page_post-1'))->toBeTrue();

    $metrics = $publication->dailySnapshots()->sole()->metrics;

    expect(data_get($metrics, 'a_retired_metric'))->toEqual($legacy)
        ->and(data_get($metrics, 'shares'))->toEqual($badProviderMetric)
        ->and(data_get($metrics, 'clicks'))->toEqual($badPeriod)
        ->and(data_get($metrics, 'saves'))->toEqual($badUnit)
        ->and(data_get($metrics, 'reach.value'))->toBe(7)
        ->and(data_get($metrics, 'reach.period_start'))->toBeNull()
        ->and(data_get($metrics, 'reactions'))->toEqual(['value' => 3])
        ->and(data_get($metrics, 'views.value'))->toBe(52);
});

test('a merge read from a publication that has moved since does nothing', function () {
    $account = SocialAccount::factory()->facebook()->create();
    $post = Post::factory()->forAccount($account, ContentType::FacebookReel)->published()->create(['platform_post_id' => 'video-9']);
    $stale = app(SyncTryPostPublication::class)->handle($post);
    $discovery = fn (string $id): AnalyticsPublication => app(UpsertAnalyticsPublication::class)->external($account, new DiscoveredPublication(
        providerPostId: $id,
        publishedAt: now()->subDay()->toImmutable(),
        contentType: PublicationContentType::Reel,
        providerMetadata: ['video_id' => 'video-9'],
    ));
    $discovery('page_post-1');
    $second = $discovery('page_post-2');

    expect(app(UpsertAnalyticsPublication::class)->mergeDiscovery($stale->replicate()->setRawAttributes($stale->getAttributes()), 'page_post-1'))->toBeTrue()
        ->and(app(UpsertAnalyticsPublication::class)->mergeDiscovery($stale, 'page_post-2'))->toBeFalse()
        ->and($stale->fresh()->remote_id)->toBe('page_post-1')
        ->and($second->fresh())->not->toBeNull();
});

test('a merged publication keeps the latest time discovery saw the post', function () {
    [$publication] = facebookVideoReadTwiceOnOneDay(['views' => 52], ['views' => 50], 1);
    $seenAt = now()->subMinutes(5)->startOfSecond();
    $publication->forceFill(['last_seen_at' => now()->subDays(3)])->save();
    AnalyticsPublication::query()->whereKeyNot($publication->id)->update(['last_seen_at' => $seenAt]);

    app(UpsertAnalyticsPublication::class)->mergeDiscovery($publication, 'page_post-1');

    expect($publication->fresh()->last_seen_at->toIso8601String())->toBe($seenAt->toIso8601String());
});

test('a discovery under another analytics key is not merged and the publication keeps its id', function () {
    $account = SocialAccount::factory()->facebook()->create();
    $post = Post::factory()->forAccount($account, ContentType::FacebookReel)->published()->create(['platform_post_id' => 'video-9']);
    $tracked = app(SyncTryPostPublication::class)->handle($post);
    $discovered = app(UpsertAnalyticsPublication::class)->external($account, new DiscoveredPublication(
        providerPostId: 'page_post-1',
        publishedAt: now()->subDay()->toImmutable(),
        contentType: PublicationContentType::Reel,
        providerMetadata: ['video_id' => 'video-9'],
    ));
    $discovered->forceFill(['social_account_key' => (string) Str::uuid7()])->save();

    expect(app(UpsertAnalyticsPublication::class)->mergeDiscovery($tracked, 'page_post-1'))->toBeFalse()
        ->and($tracked->fresh()->remote_id)->toBe('video-9')
        ->and($discovered->fresh())->not->toBeNull();

    ImportExternalPosts::execute($account);

    expect(Post::query()->imported()->exists())->toBeFalse()
        ->and($discovered->fresh()->post_id)->toBeNull();

    $this->artisan('analytics:merge-facebook-duplicates', ['--force' => true])
        ->expectsOutputToContain('Linked 0 publication(s) to their TryPost post.')
        ->assertSuccessful();
});
