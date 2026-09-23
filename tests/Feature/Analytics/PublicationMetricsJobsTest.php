<?php

declare(strict_types=1);

use App\Actions\Analytics\QueuePublicationMetricsForPage;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Analytics\CollectPublicationMetrics;
use App\Jobs\Analytics\ScheduleInstagramStoryMetrics;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\SocialAccount;
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

    expect(AnalyticsPublicationDailySnapshot::query()->where('analytics_publication_id', $included->id)->count())->toBe(1)
        ->and(AnalyticsPublicationDailySnapshot::query()->where('analytics_publication_id', $excluded->id)->count())->toBe(0);
    Http::assertSentCount(1);
});

test('regular collection respects the X and non-X refresh windows', function (Platform $platform, int $age, bool $eligible) {
    $date = CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($date);
    $publication = metricJobPublication($platform, $date->subDays($age));
    Http::fake(['*' => Http::response($platform === Platform::X
        ? ['data' => ['public_metrics' => ['like_count' => 1]]]
        : ['favourites_count' => 1])]);

    app()->call([(new CollectPublicationMetrics($publication->id, $date->toDateString())), 'handle']);

    expect(AnalyticsPublicationDailySnapshot::query()->where('analytics_publication_id', $publication->id)->exists())
        ->toBe($eligible, "{$platform->value} at {$age} days");
})->with([
    [Platform::X, 20, true],
    [Platform::X, 21, false],
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

    expect(AnalyticsPublicationDailySnapshot::query()->where('analytics_publication_id', $publication->id)->count())->toBe(1);
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
        'analytics_publication_id' => $publication->id,
        'snapshot_date' => $date->subDay()->toDateString(),
        'reactions_count' => 6,
    ]);
    Http::fake(['*' => Http::response(['error' => 'rate limit'], 429)]);
    Bus::fake([CollectPublicationMetrics::class]);

    app()->call([(new CollectPublicationMetrics($publication->id, $date->toDateString())), 'handle']);

    expect(AnalyticsPublicationDailySnapshot::query()->where('analytics_publication_id', $publication->id)->count())->toBe(1)
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
    AnalyticsPublicationDailySnapshot::factory()->create(['analytics_publication_id' => $publication->id]);
    app(QueuePublicationMetricsForPage::class)->queue($publication);
    Bus::assertDispatched(CollectPublicationMetrics::class, 1);
});

test('daily dispatcher rate limits each eligible publication independently and excludes old or v2 publications', function () {
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
    metricJobPublication(Platform::Mastodon, $date->subDays(31));
    Bus::fake([CollectPublicationMetrics::class]);

    $this->artisan('analytics:dispatch-publication-metrics')->assertExitCode(0);

    Bus::assertDispatched(CollectPublicationMetrics::class, 21);
    expect(Bus::dispatched(CollectPublicationMetrics::class)
        ->pluck('publicationId')->unique())->toHaveCount(21);
});
