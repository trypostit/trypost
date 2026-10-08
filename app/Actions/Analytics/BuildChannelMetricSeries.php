<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DateRange;
use App\Dto\Analytics\PublicationFilter;
use App\Enums\Analytics\MetricAvailability;
use App\Enums\Analytics\MetricKey;
use App\Enums\User\WeekStart;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Analytics\PeriodBuckets;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * The per-bucket series behind the channel metrics chart: the selected range and the previous range of the
 * same length, bucket by bucket. Post metrics are summed on the publish date and honour the publication
 * filter; followers stay account-wide and carry the last known count forward.
 */
class BuildChannelMetricSeries
{
    /** Ranges longer than this are bucketed by week. */
    public const int DAILY_LIMIT_DAYS = 92;

    /** Post metrics read from a snapshot column. */
    private const array COLUMN_METRICS = [
        'reach' => 'reach_count',
        'views' => 'views_count',
        'impressions' => 'impressions_count',
    ];

    /** Metrics whose period total is the last bucket, not the sum of the buckets. */
    private const array LEVEL_METRICS = ['followers', 'net_followers'];

    public function __construct(
        private readonly PeriodBuckets $buckets,
        private readonly QueryLatestPublicationSnapshots $latestSnapshots,
        private readonly ListAvailableChannelMetrics $availableMetrics,
    ) {}

    /**
     * @param  array<string, bool>|null  $availability  The channel's metric availability, when already resolved for this request.
     * @return array{resolution: string, metrics: list<string>, range: array{start: string, end: string}, previous_range: array{start: string, end: string}, current: list<array{start: string, end: string, values: array<string, int|null>}>, previous: list<array{start: string, end: string, values: array<string, int|null>}>, totals: array{current: array<string, int|null>, previous: array<string, int|null>}}
     */
    public function handle(SocialAccount $channel, string $accountKey, DateRange $current, WeekStart $weekStart = WeekStart::DEFAULT, ?PublicationFilter $filter = null, ?array $availability = null): array
    {
        $previous = $current->previous();
        $resolution = $current->days() > self::DAILY_LIMIT_DAYS ? 'weekly' : 'daily';
        $currentBuckets = $this->buckets->for($current, $weekStart, $resolution);
        $previousBuckets = array_map(fn (array $bucket): array => [
            'start' => CarbonImmutable::parse(data_get($bucket, 'start'), 'UTC')->subDays($current->days())->toDateString(),
            'end' => CarbonImmutable::parse(data_get($bucket, 'end'), 'UTC')->subDays($current->days())->toDateString(),
        ], $currentBuckets);
        $metrics = $this->availableMetrics->series($channel, $accountKey, $availability);
        $postMetrics = array_values(array_intersect($metrics, ['reach', 'views', 'impressions', 'profile_visits']));
        $points = [
            'current' => $this->emptyPoints($currentBuckets, $postMetrics),
            'previous' => $this->emptyPoints($previousBuckets, $postMetrics),
        ];
        $indexes = [
            'current' => $this->indexByDate($currentBuckets),
            'previous' => $this->indexByDate($previousBuckets),
        ];

        foreach ($this->publications($channel, $accountKey, $previous->startsAt(), $current->endsAt(), $filter) as $row) {
            $day = $current->localDate($row->provider_published_at);
            $period = array_key_exists($day, data_get($indexes, 'current')) ? 'current' : 'previous';
            $index = data_get($indexes, "{$period}.{$day}");

            if ($index === null) {
                continue;
            }

            $points[$period][$index]['values']['posts']++;

            foreach ($postMetrics as $metric) {
                $points[$period][$index]['values'][$metric] += $this->postValue($row, $metric);
            }
        }

        $followers = $this->followerHistory($accountKey, $channel->workspace_id, $previous->start, $current->observedThrough);
        $currentPoints = $this->withFollowers(data_get($points, 'current'), $followers, $current);
        $previousPoints = $this->withFollowers(data_get($points, 'previous'), $followers, $previous);

        return [
            'resolution' => $resolution,
            'metrics' => $metrics,
            'range' => $current->toArray(),
            'previous_range' => $previous->toArray(),
            'current' => $currentPoints,
            'previous' => $previousPoints,
            'totals' => [
                'current' => $this->totals($currentPoints, $metrics),
                'previous' => $this->totals($previousPoints, $metrics),
            ],
        ];
    }

    /**
     * @param  list<array{start: string, end: string}>  $buckets
     * @param  list<string>  $postMetrics
     * @return list<array{start: string, end: string, values: array<string, int|null>}>
     */
    private function emptyPoints(array $buckets, array $postMetrics): array
    {
        return array_map(fn (array $bucket): array => [
            ...$bucket,
            'values' => [
                'posts' => 0,
                'followers' => null,
                'net_followers' => null,
                ...array_fill_keys($postMetrics, 0),
            ],
        ], $buckets);
    }

    /**
     * @param  list<array{start: string, end: string}>  $buckets
     * @return array<string, int>
     */
    private function indexByDate(array $buckets): array
    {
        $indexes = [];

        foreach ($buckets as $index => $bucket) {
            for ($day = CarbonImmutable::parse(data_get($bucket, 'start'), 'UTC'); $day->toDateString() <= data_get($bucket, 'end'); $day = $day->addDay()) {
                $indexes[$day->toDateString()] = $index;
            }
        }

        return $indexes;
    }

    /** @return iterable<object> */
    private function publications(SocialAccount $channel, string $accountKey, CarbonImmutable $start, CarbonImmutable $end, ?PublicationFilter $filter): iterable
    {
        return $this->latestSnapshots->execute($channel->workspace_id, [$accountKey], $start, $end)
            ->leftJoin((new Post)->getTable().' as destination', 'destination.id', '=', 'publication.post_id')
            ->when($filter !== null, fn (Builder $filtered): Builder => $filter->apply($filtered))
            ->select([
                'publication.id', 'publication.provider_published_at',
                'metric.reach_count', 'metric.views_count', 'metric.impressions_count', 'metric.metrics',
            ])
            ->orderBy('publication.id')
            ->cursor();
    }

    private function postValue(object $row, string $metric): int
    {
        if ($metric === 'profile_visits') {
            $measured = data_get(json_decode((string) $row->metrics, true), MetricKey::ProfileVisits->value);

            return data_get($measured, 'availability') === MetricAvailability::Available->value && is_numeric(data_get($measured, 'value'))
                ? (int) data_get($measured, 'value') : 0;
        }

        return (int) data_get($row, data_get(self::COLUMN_METRICS, $metric));
    }

    /**
     * Follower counts by date, oldest first, starting with the last count before the window.
     *
     * @return array<string, int>
     */
    private function followerHistory(string $accountKey, string $workspaceId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $snapshots = AnalyticsAccountDailySnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->where('social_account_key', $accountKey)
            ->whereNotNull('followers_count');
        $before = (clone $snapshots)->where('date', '<', $start->toDateString())->latest('date')->first(['date', 'followers_count']);
        $history = $before === null ? [] : [$before->date->toDateString() => $before->followers_count];

        foreach ((clone $snapshots)->whereBetween('date', [$start->toDateString(), $end->toDateString()])->orderBy('date')->get(['date', 'followers_count']) as $snapshot) {
            $history[$snapshot->date->toDateString()] = $snapshot->followers_count;
        }

        return $history;
    }

    /**
     * @param  list<array{start: string, end: string, values: array<string, int|null>}>  $points
     * @param  array<string, int>  $history
     * @return list<array{start: string, end: string, values: array<string, int|null>}>
     */
    private function withFollowers(array $points, array $history, DateRange $range): array
    {
        $observed = array_filter(
            array_keys($history),
            fn (string $day): bool => $day >= $range->start->toDateString() && $day <= $range->observedThrough->toDateString(),
        );
        $baseline = $this->followersAt($history, $range->start->subDay()->toDateString())
            ?? (count($observed) > 1 ? $this->firstBetween($history, $range->start->toDateString(), $range->observedThrough->toDateString()) : null);
        $last = array_key_last($points);

        foreach ($points as $index => &$point) {
            $through = $index === $last ? $range->observedThrough->toDateString() : data_get($point, 'end');
            $followers = $this->followersAt($history, $through);
            $point['values']['followers'] = $followers;
            $point['values']['net_followers'] = $followers === null || $baseline === null ? null : $followers - $baseline;
        }
        unset($point);

        return $points;
    }

    /** @param  array<string, int>  $history */
    private function followersAt(array $history, string $date): ?int
    {
        $value = null;

        foreach ($history as $day => $followers) {
            if ($day > $date) {
                break;
            }

            $value = $followers;
        }

        return $value;
    }

    /** @param  array<string, int>  $history */
    private function firstBetween(array $history, string $start, string $end): ?int
    {
        foreach ($history as $day => $followers) {
            if ($day >= $start && $day <= $end) {
                return $followers;
            }
        }

        return null;
    }

    /**
     * @param  list<array{start: string, end: string, values: array<string, int|null>}>  $points
     * @param  list<string>  $metrics
     * @return array<string, int|null>
     */
    private function totals(array $points, array $metrics): array
    {
        $totals = [];

        foreach ($metrics as $metric) {
            $values = array_map(fn (array $point): ?int => data_get($point, "values.{$metric}"), $points);
            $totals[$metric] = in_array($metric, self::LEVEL_METRICS, true)
                ? data_get($values, array_key_last($values))
                : array_sum(array_filter($values, fn (?int $value): bool => $value !== null));
        }

        return $totals;
    }
}
