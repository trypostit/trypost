<?php

declare(strict_types=1);

use App\Actions\Analytics\BuildChannelMetricSeries;
use App\Actions\Analytics\BuildWorkspaceAnalyticsReport;
use App\Actions\Analytics\ListChannelPublicationPerformance;
use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Enums\User\WeekStart;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->travelTo('2026-11-20 12:00:00 UTC');

    $this->workspace = Workspace::factory()->create();
    $this->account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $this->publishAt = function (string $instant, int $reactions = 1): AnalyticsPublication {
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->account->id,
            'social_account_key' => $this->account->id,
            'network' => $this->account->platform->network(),
            'platform_user_id' => $this->account->platform_user_id,
            'platform' => $this->account->platform,
            'provider_published_at' => CarbonImmutable::parse($instant, 'UTC'),
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create([
            'publication_id' => $publication->id,
            'date' => CarbonImmutable::parse($instant, 'UTC')->toDateString(),
            'reactions_count' => $reactions,
        ]);

        return $publication;
    };
});

/**
 * @param  array{range?: string, start?: string, end?: string}  $range
 * @return array<string, mixed>
 */
function viewerTimezoneReport(Workspace $workspace, array $range, string $timezone, WeekStart $weekStart = WeekStart::DEFAULT): array
{
    ['selection' => $selection, 'clamped' => $clamped] = app(ResolveAnalyticsRangePreset::class)->selection($range, $timezone);

    return app(BuildWorkspaceAnalyticsReport::class)->forSelection($workspace, $selection, clampToBounds: $clamped, weekStart: $weekStart);
}

test('a post at 22:00 in sao paulo counts on the viewer local day, not the next utc day', function () {
    $evening = ($this->publishAt)('2026-09-11 01:00:00', 5);
    ($this->publishAt)('2026-09-10 02:00:00', 3);

    $report = viewerTimezoneReport($this->workspace, ['start' => '2026-09-10', 'end' => '2026-09-10'], 'America/Sao_Paulo');

    expect(data_get($report, 'summary.posts.value'))->toBe(1)
        ->and(data_get($report, 'summary.posts.previous'))->toBe(1)
        ->and(data_get($report, 'summary.reactions.value'))->toBe(5)
        ->and(data_get($report, 'summary.reactions.previous'))->toBe(3)
        ->and(data_get($report, 'posts.buckets.0.start'))->toBe('2026-09-10')
        ->and(data_get($report, 'posts.buckets.0.total'))->toBe(1)
        ->and(data_get($report, 'top_posts.reactions.0.id'))->toBe($evening->id);
});

test('the same instants land on their utc days for a utc viewer', function () {
    ($this->publishAt)('2026-09-11 01:00:00');
    ($this->publishAt)('2026-09-10 02:00:00');

    $report = viewerTimezoneReport($this->workspace, ['start' => '2026-09-10', 'end' => '2026-09-10'], 'UTC');

    expect(data_get($report, 'summary.posts.value'))->toBe(1)
        ->and(data_get($report, 'summary.posts.previous'))->toBe(0);
});

test('a custom range starting on the local day of the earliest post is not clamped to its utc day', function () {
    ($this->publishAt)('2026-09-04 01:00:00');

    $report = viewerTimezoneReport($this->workspace, ['start' => '2026-09-03', 'end' => '2026-09-03'], 'America/Sao_Paulo');

    expect(data_get($report, 'bounds.min'))->toBe('2026-09-03')
        ->and(data_get($report, 'range'))->toBe(['start' => '2026-09-03', 'end' => '2026-09-03'])
        ->and(data_get($report, 'summary.posts.value'))->toBe(1);
});

test('month to date covers the viewer month, not the utc one', function () {
    $this->travelTo('2026-10-01 02:00:00 UTC');
    ($this->publishAt)('2026-09-01 02:00:00');
    ($this->publishAt)('2026-09-01 04:00:00');
    ($this->publishAt)('2026-10-01 01:00:00');

    $report = viewerTimezoneReport($this->workspace, ['range' => 'mtd'], 'America/Sao_Paulo');

    expect(data_get($report, 'range'))->toBe(['start' => '2026-09-01', 'end' => '2026-09-30'])
        ->and(data_get($report, 'summary.posts.value'))->toBe(2)
        ->and(data_get($report, 'summary.posts.previous'))->toBe(1);
});

test('a range crossing the end of daylight saving time keeps whole local days', function () {
    ($this->publishAt)('2026-10-29 03:30:00');
    ($this->publishAt)('2026-11-01 04:30:00');
    ($this->publishAt)('2026-11-02 04:30:00');
    ($this->publishAt)('2026-11-05 04:30:00');
    ($this->publishAt)('2026-11-05 05:30:00');

    $report = viewerTimezoneReport($this->workspace, ['start' => '2026-10-29', 'end' => '2026-11-04'], 'America/New_York');
    $buckets = collect(data_get($report, 'posts.buckets'))->mapWithKeys(fn (array $bucket): array => [data_get($bucket, 'start') => data_get($bucket, 'total')]);

    expect(data_get($report, 'summary.posts.value'))->toBe(3)
        ->and(data_get($report, 'summary.posts.previous'))->toBe(1)
        ->and($buckets->all())->toBe([
            '2026-10-29' => 0,
            '2026-10-30' => 0,
            '2026-10-31' => 0,
            '2026-11-01' => 2,
            '2026-11-02' => 0,
            '2026-11-03' => 0,
            '2026-11-04' => 1,
        ]);
});

test('weekly buckets place a saturday evening post in the week ending that saturday', function () {
    ($this->publishAt)('2026-09-01 15:00:00');
    ($this->publishAt)('2026-09-06 01:00:00');
    ($this->publishAt)('2026-09-30 15:00:00');

    $report = viewerTimezoneReport($this->workspace, ['start' => '2026-09-01', 'end' => '2026-09-30'], 'America/Sao_Paulo', WeekStart::Sunday);

    expect(data_get($report, 'posts.resolution'))->toBe('weekly')
        ->and(data_get($report, 'posts.buckets.0'))->toMatchArray(['start' => '2026-09-01', 'end' => '2026-09-05', 'total' => 2])
        ->and(data_get($report, 'posts.buckets.1.total'))->toBe(0);
});

test('channel series and post list count posts on the viewer local day', function () {
    $evening = ($this->publishAt)('2026-09-11 01:00:00');
    ($this->publishAt)('2026-09-10 02:00:00');
    $selection = app(ResolveAnalyticsRangePreset::class)->selection(['start' => '2026-09-10', 'end' => '2026-09-10'], 'America/Sao_Paulo')['selection'];
    $range = app(BuildWorkspaceAnalyticsReport::class)->resolveRange($this->workspace, $selection, [$this->account->id])['range'];

    $series = app(BuildChannelMetricSeries::class)->handle($this->account, $this->account->id, $range);

    expect(data_get($series, 'current.0.start'))->toBe('2026-09-10')
        ->and(data_get($series, 'current.0.values.posts'))->toBe(1)
        ->and(data_get($series, 'previous.0.start'))->toBe('2026-09-09')
        ->and(data_get($series, 'previous.0.values.posts'))->toBe(1)
        ->and(app(ListChannelPublicationPerformance::class)->handle($this->account, $range, 'reactions', $this->account->id)->pluck('id')->all())
        ->toBe([$evening->id]);
});
