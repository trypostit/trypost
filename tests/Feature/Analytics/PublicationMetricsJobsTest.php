<?php

declare(strict_types=1);

use App\Actions\Analytics\QueuePublicationMetricsForPage;
use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationAvailability;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Analytics\CollectPublicationMetrics;
use App\Jobs\Analytics\ScheduleInstagramStoryMetrics;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Metrics\MastodonPublicationMetricsCollector;
use App\Services\Analytics\Collectors\Metrics\PublicationMetricsCollectorFactory;
use App\Support\Analytics\SyncCadence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
});

function metricJobPublication(Platform $platform, CarbonImmutable $publishedAt): AnalyticsPublication
{
    $account = SocialAccount::factory()->create(['platform' => $platform]);

    return AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => $platform,
        'network' => $platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'provider_published_at' => $publishedAt,
    ]);
}

function discoveredFor(AnalyticsPublication $publication): DiscoveredPublication
{
    return new DiscoveredPublication(
        providerPostId: $publication->remote_id,
        publishedAt: $publication->provider_published_at->toImmutable(),
        contentType: PublicationContentType::Text,
    );
}

test('metric job writes a daily snapshot once and never calls providers for excluded networks', function () {
    $date = CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($date);
    $included = metricJobPublication(Platform::Mastodon, $date->subDay());
    $excluded = metricJobPublication(Platform::LinkedIn, $date->subDay());
    Http::fake(['*' => Http::response(['favourites_count' => 0, 'replies_count' => 2])]);

    $job = new CollectPublicationMetrics($included->id, $date->toDateString());
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    app()->call([(new CollectPublicationMetrics($excluded->id, $date->toDateString())), 'handle']);

    expect(AnalyticsPublicationDailySnapshot::query()->where('publication_id', $included->id)->count())->toBe(1)
        ->and(AnalyticsPublicationDailySnapshot::query()->where('publication_id', $excluded->id)->count())->toBe(0);
    Http::assertSentCount(1);
});

test('TikTok metric job reconciles a public video id before persisting metrics', function () {
    $date = CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($date);
    $account = SocialAccount::factory()->create(['platform' => Platform::TikTok]);
    $post = Post::factory()->forAccount($account, ContentType::TikTokVideo)->published()->create([
        'platform_post_id' => 'v_pub_abc',
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_id' => $post->id,
        'network' => Platform::TikTok->network(),
        'platform' => Platform::TikTok,
        'platform_user_id' => $account->platform_user_id,
        'remote_id' => 'v_pub_abc',
        'origin' => PublicationOrigin::TryPost,
        'provider_published_at' => $date->subDay(),
    ]);
    Http::fake(['*' => Http::sequence()
        ->push(['data' => ['publicaly_available_post_id' => ['123456789']]])
        ->push(['error' => ['code' => 'ok'], 'data' => ['videos' => [[
            'id' => '123456789', 'view_count' => 12, 'like_count' => 1,
        ]]]])]);

    app()->call([(new CollectPublicationMetrics($publication->id, $date->toDateString())), 'handle']);

    expect($publication->fresh()->remote_id)->toBe('123456789')
        ->and($post->fresh()->platform_post_id)->toBe('123456789')
        ->and($publication->dailySnapshots()->first()->views_count)->toBe(12);
    Http::assertSentCount(2);
});

test('regular collection respects the X and non-X refresh windows', function (Platform $platform, int $age, bool $eligible) {
    $date = CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($date);
    $publication = metricJobPublication($platform, $date->subDays($age));
    Http::fake(['*' => Http::response($platform === Platform::X
        ? ['data' => ['public_metrics' => ['like_count' => 1]]]
        : ['favourites_count' => 1])]);

    app()->call([(new CollectPublicationMetrics($publication->id, $date->toDateString())), 'handle']);

    expect(AnalyticsPublicationDailySnapshot::query()->where('publication_id', $publication->id)->exists())
        ->toBe($eligible, "{$platform->value} at {$age} days");
})->with([
    [Platform::X, 28, true],
    [Platform::X, 29, false],
    [Platform::Mastodon, 30, true],
    [Platform::Mastodon, 31, false],
]);

test('an old imported publication receives one baseline measurement only', function () {
    $date = CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($date);
    $publication = metricJobPublication(Platform::Mastodon, $date->subDays(180));
    Http::fake(['*' => Http::response(['favourites_count' => 5])]);
    $job = new CollectPublicationMetrics($publication->id, $date->toDateString(), baseline: true);

    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    expect(AnalyticsPublicationDailySnapshot::query()->where('publication_id', $publication->id)->count())->toBe(1);
    Http::assertSentCount(1);
});

test('story scheduler queues bounded in-lifetime checks', function () {
    $date = CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($date);
    $publication = metricJobPublication(Platform::Instagram, $date);
    $publication->update(['content_type' => PublicationContentType::Story]);
    Bus::fake([CollectPublicationMetrics::class]);

    app()->call([(new ScheduleInstagramStoryMetrics($publication->id)), 'handle']);

    Bus::assertDispatched(CollectPublicationMetrics::class, 3);
});

test('transient metric failure preserves the last measured value and queues only the failed item', function () {
    $date = CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($date);
    $publication = metricJobPublication(Platform::Mastodon, $date->subDay());
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => $date->subDay()->toDateString(),
        'reactions_count' => 6,
    ]);
    Http::fake(['*' => Http::response(['error' => 'rate limit'], 429)]);
    Bus::fake([CollectPublicationMetrics::class]);

    app()->call([(new CollectPublicationMetrics($publication->id, $date->toDateString())), 'handle']);

    expect(AnalyticsPublicationDailySnapshot::query()->where('publication_id', $publication->id)->count())->toBe(1)
        ->and($publication->dailySnapshots()->first()->reactions_count)->toBe(6);
    Bus::assertDispatched(CollectPublicationMetrics::class, fn (CollectPublicationMetrics $job): bool => $job->publicationId === $publication->id && $job->retryNumber === 1);
});

test('a disconnected account is skipped even when its publication remains available', function () {
    $date = CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($date);
    $publication = metricJobPublication(Platform::Mastodon, $date->subDay());
    $publication->socialAccount->update(['status' => Status::Disconnected]);
    Http::fake(['*' => Http::response(['favourites_count' => 3])]);

    app()->call([(new CollectPublicationMetrics($publication->id, $date->toDateString())), 'handle']);

    expect($publication->dailySnapshots()->exists())->toBeFalse();
    Http::assertNothingSent();
});

test('an old discovered post queues a baseline only until it has one snapshot', function () {
    $date = CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($date);
    $publication = metricJobPublication(Platform::Mastodon, $date->subDays(180));
    Bus::fake([CollectPublicationMetrics::class]);

    app(QueuePublicationMetricsForPage::class)->queue($publication);

    Bus::assertDispatched(CollectPublicationMetrics::class, fn (CollectPublicationMetrics $job): bool => $job->baseline);
    AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id]);
    app(QueuePublicationMetricsForPage::class)->queue($publication);
    Bus::assertDispatched(CollectPublicationMetrics::class, 1);
});

test('daily dispatcher includes old publications without a baseline and excludes v2 publications', function () {
    $date = CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($date);
    $first = metricJobPublication(Platform::TikTok, $date->subDay());

    for ($number = 0; $number < 20; $number++) {
        AnalyticsPublication::factory()->create([
            'workspace_id' => $first->workspace_id,
            'social_account_id' => $first->social_account_id,
            'social_account_key' => $first->social_account_key,
            'platform' => Platform::TikTok,
            'network' => Platform::TikTok->network(),
            'platform_user_id' => $first->platform_user_id,
            'provider_published_at' => $date->subDay(),
        ]);
    }

    metricJobPublication(Platform::LinkedIn, $date->subDay());
    $old = metricJobPublication(Platform::Mastodon, $date->subDays(31));
    $expiredStory = metricJobPublication(Platform::Instagram, $date->subDays(31));
    $expiredStory->update(['content_type' => PublicationContentType::Story]);
    Bus::fake([CollectPublicationMetrics::class]);

    $this->artisan('analytics:dispatch-publication-metrics')->assertExitCode(0);

    Bus::assertDispatched(CollectPublicationMetrics::class, 22);
    Bus::assertDispatched(CollectPublicationMetrics::class, fn (CollectPublicationMetrics $job): bool => $job->publicationId === $old->id && $job->baseline);
    expect(Bus::dispatched(CollectPublicationMetrics::class)
        ->pluck('publicationId')->unique())->toHaveCount(22);
});

test('an old publication with an unsuccessful baseline is retried by the next daily dispatch', function () {
    CarbonImmutable::setTestNow('2026-09-23 12:00:00 UTC');
    $publication = metricJobPublication(Platform::Mastodon, CarbonImmutable::now('UTC')->subDays(180));
    Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
    Bus::fake([CollectPublicationMetrics::class]);

    app()->call([(new CollectPublicationMetrics($publication->id, '2026-09-23', baseline: true)), 'handle']);
    expect($publication->dailySnapshots()->exists())->toBeFalse();

    CarbonImmutable::setTestNow('2026-09-24 02:00:00 UTC');
    Bus::fake([CollectPublicationMetrics::class]);
    $this->artisan('analytics:dispatch-publication-metrics')->assertSuccessful();

    Bus::assertDispatched(CollectPublicationMetrics::class, fn (CollectPublicationMetrics $job): bool => $job->publicationId === $publication->id
        && $job->observationDate === '2026-09-24'
        && $job->baseline);
});

test('analytics jobs are rate limited per account with a shared guard only where the network limits per app', function (Platform $platform, ?string $sharedKey) {
    $publication = metricJobPublication($platform, CarbonImmutable::now('UTC')->subDay());
    $job = new CollectPublicationMetrics($publication->id, CarbonImmutable::now('UTC')->toDateString());

    $limits = collect($job->analyticsRateLimits());

    expect($limits->first()->key)->toBe("{$platform->network()}:account:{$publication->social_account_id}")
        ->and($limits->first()->maxAttempts)->toBe(30)
        ->and($limits->get(1)?->key)->toBe($sharedKey);
})->with([
    'instagram is limited per token' => [Platform::Instagram, null],
    'facebook is limited per page token' => [Platform::Facebook, null],
    'x shares the app limit' => [Platform::X, 'x:app'],
    'youtube shares the project quota' => [Platform::YouTube, 'youtube:project'],
    'tiktok shares the app limit' => [Platform::TikTok, 'tiktok:app'],
]);

test('bluesky analytics share the documented per-ip limit of the account pds host', function (?string $service, string $sharedKey) {
    $publication = metricJobPublication(Platform::Bluesky, CarbonImmutable::now('UTC')->subDay());
    config()->set('trypost.platforms.bluesky.default_service', 'https://bsky.social');
    $publication->socialAccount->update(['meta' => $service === null ? [] : ['service' => $service]]);
    $job = new CollectPublicationMetrics($publication->id, CarbonImmutable::now('UTC')->toDateString());

    $shared = collect($job->analyticsRateLimits())->get(1);

    expect($shared->key)->toBe($sharedKey)
        ->and($shared->maxAttempts)->toBe(500)
        ->and($shared->decaySeconds)->toBe(60);
})->with([
    'a self-hosted pds' => ['https://pds.example.com', 'bluesky:pds:pds.example.com'],
    'the default service' => [null, 'bluesky:pds:bsky.social'],
]);

test('metric and follower jobs back off and stop after a few unexpected exceptions', function () {
    $metrics = new CollectPublicationMetrics(fake()->uuid(), '2026-09-23');
    $followers = new CollectAccountDailySnapshot(fake()->uuid(), '2026-09-23');

    expect($metrics->maxExceptions)->toBe(3)
        ->and($metrics->backoff())->toBe([300, 1800, 3600])
        ->and($followers->maxExceptions)->toBe(3)
        ->and($followers->backoff())->toBe([300, 1800, 3600]);
});

test('an unexpected collector exception is thrown so the worker counts it against max exceptions', function () {
    $date = CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($date);
    $publication = metricJobPublication(Platform::Mastodon, $date->subDay());
    $collector = Mockery::mock(MastodonPublicationMetricsCollector::class);
    $collector->shouldReceive('collect')->andThrow(new RuntimeException('Unexpected payload'));
    $factory = Mockery::mock(PublicationMetricsCollectorFactory::class);
    $factory->shouldReceive('for')->andReturn($collector);

    expect(fn () => app()->call([new CollectPublicationMetrics($publication->id, $date->toDateString()), 'handle'], ['collectors' => $factory]))
        ->toThrow(RuntimeException::class, 'Unexpected payload');
});

test('a metric job that outlives its observation day hands off to the current day', function () {
    Bus::fake([CollectPublicationMetrics::class]);
    $now = CarbonImmutable::parse('2026-09-24 00:30:00', 'UTC');
    CarbonImmutable::setTestNow($now);
    $publication = metricJobPublication(Platform::Mastodon, $now->subDays(2));
    $job = new CollectPublicationMetrics($publication->id, '2026-09-23', baseline: true);

    app()->call([$job, 'handle']);

    expect($job->retryUntil()->toDateTimeString())->toBe('2026-09-24 23:59:59')
        ->and(AnalyticsPublicationDailySnapshot::query()->count())->toBe(0);
    Bus::assertDispatched(CollectPublicationMetrics::class, fn (CollectPublicationMetrics $next): bool => $next->publicationId === $publication->id
        && $next->observationDate === '2026-09-24'
        && $next->baseline);
});

test('a follower job that outlives its observation day hands off to the current day', function () {
    Bus::fake([CollectAccountDailySnapshot::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-24 00:30:00', 'UTC'));
    $account = SocialAccount::factory()->create(['platform' => Platform::Mastodon]);
    $job = new CollectAccountDailySnapshot($account->id, '2026-09-23');

    app()->call([$job, 'handle']);

    expect($job->retryUntil()->toDateTimeString())->toBe('2026-09-24 23:59:59');
    Bus::assertDispatched(CollectAccountDailySnapshot::class, fn (CollectAccountDailySnapshot $next): bool => $next->socialAccountId === $account->id
        && $next->observationDate === '2026-09-24');
});

test('a publication the network reports as gone is marked deleted and no longer dispatched', function () {
    CarbonImmutable::setTestNow('2026-09-23 12:00:00 UTC');
    $publication = metricJobPublication(Platform::Mastodon, CarbonImmutable::now('UTC')->subDays(180));
    Http::fake(['*' => Http::response(['error' => 'Record not found'], 404)]);

    app()->call([new CollectPublicationMetrics($publication->id, '2026-09-23', baseline: true), 'handle']);

    expect($publication->fresh()->availability)->toBe(PublicationAvailability::Deleted);

    CarbonImmutable::setTestNow('2026-09-24 03:00:00 UTC');
    Bus::fake([CollectPublicationMetrics::class]);
    $this->artisan('analytics:dispatch-publication-metrics')->assertSuccessful();

    Bus::assertNotDispatched(CollectPublicationMetrics::class, fn (CollectPublicationMetrics $job): bool => $job->publicationId === $publication->id);
});

test('a baseline that keeps failing is marked unavailable after the failure cap', function () {
    CarbonImmutable::setTestNow('2026-09-23 12:00:00 UTC');
    $publication = metricJobPublication(Platform::Mastodon, CarbonImmutable::now('UTC')->subDays(180));
    Http::fake(['*' => Http::response(['unexpected' => true])]);

    foreach (['2026-09-23', '2026-09-24'] as $day) {
        CarbonImmutable::setTestNow("{$day} 12:00:00 UTC");
        app()->call([new CollectPublicationMetrics($publication->id, $day, baseline: true), 'handle']);
    }

    expect($publication->fresh()->availability)->toBe(PublicationAvailability::Available)
        ->and($publication->fresh()->metric_failures)->toBe(2);

    CarbonImmutable::setTestNow('2026-09-25 12:00:00 UTC');
    app()->call([new CollectPublicationMetrics($publication->id, '2026-09-25', baseline: true), 'handle']);

    expect($publication->fresh()->availability)->toBe(PublicationAvailability::Unavailable)
        ->and($publication->fresh()->metric_failures)->toBe(CollectPublicationMetrics::MAX_METRIC_FAILURES);
});

test('a malformed reading of a recent publication is not counted against it', function () {
    CarbonImmutable::setTestNow('2026-09-23 12:00:00 UTC');
    $publication = metricJobPublication(Platform::Mastodon, CarbonImmutable::now('UTC')->subDay());
    Http::fake(['*' => Http::response(['unexpected' => true])]);

    app()->call([new CollectPublicationMetrics($publication->id, '2026-09-23'), 'handle']);

    expect($publication->fresh()->metric_failures)->toBe(0)
        ->and($publication->fresh()->availability)->toBe(PublicationAvailability::Available);
});

test('the daily run reads x posts only on the scheduled ages', function () {
    $now = CarbonImmutable::parse('2026-10-10 03:00:00', 'UTC');
    CarbonImmutable::setTestNow($now);
    $read = [];

    foreach ([1, 2, 3, 4, 5, 6, 7, 8, 13, 14, 15, 20, 27, 28, 29] as $age) {
        $publication = metricJobPublication(Platform::X, $now->subDays($age)->setTime(15, 0));
        AnalyticsPublicationDailySnapshot::factory()->create([
            'publication_id' => $publication->id,
            'date' => $now->subDays($age)->toDateString(),
        ]);

        if (in_array($age, SyncCadence::X_METRICS_DAYS, true)) {
            $read[] = $publication->id;
        }
    }

    Bus::fake([CollectPublicationMetrics::class]);
    $this->artisan('analytics:dispatch-publication-metrics')->assertSuccessful();

    expect(Bus::dispatched(CollectPublicationMetrics::class)->pluck('publicationId')->sort()->values()->all())
        ->toBe(collect($read)->sort()->values()->all());
});

test('other networks keep the daily thirty day window', function () {
    $now = CarbonImmutable::parse('2026-10-10 03:00:00', 'UTC');
    CarbonImmutable::setTestNow($now);

    foreach ([1, 4, 29, 30] as $age) {
        $publication = metricJobPublication(Platform::Instagram, $now->subDays($age)->setTime(15, 0));
        AnalyticsPublicationDailySnapshot::factory()->create([
            'publication_id' => $publication->id,
            'date' => $now->subDays($age)->toDateString(),
        ]);
    }

    Bus::fake([CollectPublicationMetrics::class]);
    $this->artisan('analytics:dispatch-publication-metrics')->assertSuccessful();

    Bus::assertDispatchedTimes(CollectPublicationMetrics::class, 4);
});

test('a published post is first read an hour after publishing on every network', function (Platform $platform) {
    $now = CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($now);
    $publication = metricJobPublication($platform, $now);
    Bus::fake([CollectPublicationMetrics::class]);

    app(QueuePublicationMetricsForPage::class)->queue($publication);

    Bus::assertDispatched(CollectPublicationMetrics::class, fn (CollectPublicationMetrics $job): bool => $job->publicationId === $publication->id
        && $job->observationDate === '2026-10-10'
        && CarbonImmutable::parse($job->delay)->equalTo($now->addHour()));
})->with([Platform::X, Platform::Instagram, Platform::Mastodon]);

test('a late post is first read under the next utc date', function () {
    $now = CarbonImmutable::parse('2026-10-10 23:30:00', 'UTC');
    CarbonImmutable::setTestNow($now);
    $publication = metricJobPublication(Platform::X, $now);
    Bus::fake([CollectPublicationMetrics::class]);

    app(QueuePublicationMetricsForPage::class)->queue($publication);

    Bus::assertDispatched(CollectPublicationMetrics::class, fn (CollectPublicationMetrics $job): bool => $job->observationDate === '2026-10-11');
});

test('a post found more than an hour after publishing is read right away', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00 UTC');
    $publication = metricJobPublication(Platform::Instagram, CarbonImmutable::now('UTC')->subHours(3));
    Bus::fake([CollectPublicationMetrics::class]);

    app(QueuePublicationMetricsForPage::class)->queue($publication);

    Bus::assertDispatched(CollectPublicationMetrics::class, fn (CollectPublicationMetrics $job): bool => CarbonImmutable::parse($job->delay)->equalTo(CarbonImmutable::now('UTC')));
});

test('discovery reads only posts that were never measured', function (Platform $platform) {
    CarbonImmutable::setTestNow('2026-10-10 00:00:00 UTC');
    $measured = metricJobPublication($platform, CarbonImmutable::now('UTC')->subHours(5));
    AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $measured->id, 'date' => '2026-10-09']);
    $native = AnalyticsPublication::factory()->create([
        'workspace_id' => $measured->workspace_id,
        'social_account_id' => $measured->social_account_id,
        'social_account_key' => $measured->social_account_key,
        'platform' => $platform,
        'network' => $platform->network(),
        'platform_user_id' => $measured->platform_user_id,
        'provider_published_at' => CarbonImmutable::now('UTC')->subHours(3),
    ]);
    Bus::fake([CollectPublicationMetrics::class]);

    app(QueuePublicationMetricsForPage::class)->handle($measured->socialAccount, new PublicationPage([
        discoveredFor($measured),
        discoveredFor($native),
    ], null, true));

    Bus::assertDispatchedTimes(CollectPublicationMetrics::class, 1);
    Bus::assertDispatched(CollectPublicationMetrics::class, fn (CollectPublicationMetrics $job): bool => $job->publicationId === $native->id);
})->with([Platform::X, Platform::Instagram]);

test('the daily run leaves a fresh post to its first read an hour after publishing', function (Platform $platform) {
    $now = CarbonImmutable::parse('2026-10-10 03:00:00', 'UTC');
    CarbonImmutable::setTestNow($now);
    $fresh = metricJobPublication($platform, $now->subMinutes(30));
    $settled = metricJobPublication($platform, $now->subMinutes(90));
    Bus::fake([CollectPublicationMetrics::class]);

    $this->artisan('analytics:dispatch-publication-metrics')->assertSuccessful();

    Bus::assertNotDispatched(CollectPublicationMetrics::class, fn (CollectPublicationMetrics $job): bool => $job->publicationId === $fresh->id);
    Bus::assertDispatched(CollectPublicationMetrics::class, fn (CollectPublicationMetrics $job): bool => $job->publicationId === $settled->id);
})->with([Platform::X, Platform::Instagram]);
