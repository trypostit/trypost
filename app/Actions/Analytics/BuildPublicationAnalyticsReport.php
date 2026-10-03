<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DateRange;
use App\Enums\Analytics\MetricAvailability;
use App\Enums\Analytics\MetricKey;
use App\Enums\User\WeekStart;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\Workspace;
use App\Support\Analytics\MetricComparison;
use App\Support\Analytics\PeriodBuckets;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\LazyCollection;

class BuildPublicationAnalyticsReport
{
    /**
     * Metrics compared per channel in the Performance table, in display order.
     */
    public const array PERFORMANCE_METRICS = [
        'posts', 'reactions', 'comments', 'engagement_rate', 'reposts', 'impressions', 'clicks', 'views',
        'shares', 'saves', 'follows_gained', 'reach', 'watch_time_minutes', 'average_watch_time_seconds',
    ];

    public function __construct(
        private readonly PeriodBuckets $buckets,
        private readonly QueryLatestPublicationSnapshots $latestSnapshots,
    ) {}

    /**
     * @param  list<string>|null  $accountKeys  Analytics account keys to scope to; null means every account.
     * @param  list<string>  $labelIds  Only count publications of TryPost posts carrying any of these labels.
     * @param  bool  $untagged  Also count publications without labels, including posts published outside TryPost.
     * @param  WeekStart  $weekStart  The viewer's week start; weekly buckets end on its last day.
     * @return array<string, mixed>
     */
    public function execute(Workspace $workspace, DateRange $previous, DateRange $current, ?array $accountKeys = null, array $labelIds = [], bool $untagged = false, WeekStart $weekStart = WeekStart::DEFAULT): array
    {
        $currentTotals = $this->emptyTotals();
        $previousTotals = $this->emptyTotals();
        $currentAccounts = [];
        $previousAccounts = [];
        $topReactions = [];
        $topComments = [];
        $buckets = $this->buckets->for($current, $weekStart);
        $bucketIndexByDate = [];
        $bucketCounts = [];

        foreach ($buckets as $index => $bucket) {
            for ($day = CarbonImmutable::parse(data_get($bucket, 'start'), 'UTC'); $day->toDateString() <= data_get($bucket, 'end'); $day = $day->addDay()) {
                $bucketIndexByDate[$day->toDateString()] = $index;
            }
        }

        foreach ($this->publications($workspace, $previous->start, $current->observedThrough, $accountKeys, $labelIds, $untagged) as $row) {
            $key = $row->social_account_key;

            if (! $this->inRange($row->provider_published_at, $current)) {
                $previousTotals = $this->addTotals($previousTotals, $row);
                $previousAccounts[$key] = $this->addTotals(
                    data_get($previousAccounts, $key, $this->emptyTotals()),
                    $row,
                );

                continue;
            }

            $currentTotals = $this->addTotals($currentTotals, $row);
            $account = data_get($currentAccounts, $key, ['row' => $row, 'totals' => $this->emptyTotals()]);
            $account['totals'] = $this->addTotals(data_get($account, 'totals'), $row);
            $currentAccounts[$key] = $account;
            $this->retainTopPublication($topReactions, $row, 'reactions_count');
            $this->retainTopPublication($topComments, $row, 'comments_count');

            $date = min(substr((string) $row->provider_published_at, 0, 10), $current->end->toDateString());
            $index = data_get($bucketIndexByDate, $date);

            if ($index !== null) {
                $bucketCounts[$index][$key] = (int) data_get($bucketCounts, "{$index}.{$key}", 0) + 1;
            }
        }

        $postAccounts = [];

        foreach ($currentAccounts as $key => $account) {
            $row = data_get($account, 'row');
            $postAccounts[] = [
                'social_account_key' => $key,
                'platform' => $row->platform,
                'name' => $row->account_display_name,
                'username' => $row->account_username,
                'avatar_url' => $row->account_avatar_url,
                'count' => data_get($account, 'totals.posts'),
            ];
        }

        foreach ($buckets as $index => &$bucket) {
            $bucket['accounts'] = array_fill_keys(array_keys($currentAccounts), 0);

            foreach (data_get($bucketCounts, $index, []) as $key => $count) {
                $bucket['accounts'][$key] = $count;
            }

            $bucket['total'] = array_sum(data_get($bucket, 'accounts'));
        }
        unset($bucket);

        return [
            'current_totals' => $this->finalizeTotals($currentTotals),
            'previous_totals' => $this->finalizeTotals($previousTotals),
            'posts' => [
                'resolution' => $this->buckets->resolution($current),
                'accounts' => $postAccounts,
                'buckets' => $buckets,
            ],
            'top_posts' => [
                'reactions' => $this->top($topReactions),
                'comments' => $this->top($topComments),
            ],
            'performance' => $this->performance($currentAccounts, $previousAccounts),
        ];
    }

    /**
     * @param  list<string>|null  $accountKeys
     * @param  list<string>  $labelIds
     */
    private function publications(Workspace $workspace, CarbonImmutable $start, CarbonImmutable $end, ?array $accountKeys, array $labelIds, bool $untagged): LazyCollection
    {
        return $this->query($workspace, $start, $end, $accountKeys, $labelIds, $untagged)->cursor();
    }

    /**
     * Publications in the window with their latest saved metrics, filtered the same way as the report.
     *
     * @param  list<string>|null  $accountKeys
     * @param  list<string>  $labelIds
     */
    public function query(Workspace $workspace, CarbonImmutable $start, CarbonImmutable $end, ?array $accountKeys = null, array $labelIds = [], bool $untagged = false): Builder
    {
        $labelPivot = (new Post)->labels()->getTable();
        $labels = fn (Builder $labelled): Builder => $labelled
            ->selectRaw('1')
            ->from($labelPivot)
            ->whereColumn("{$labelPivot}.post_id", 'destination.post_id');

        return $this->latestSnapshots->execute($workspace->id, $accountKeys, $start, $end)
            ->leftJoin((new PostPlatform)->getTable().' as destination', 'destination.id', '=', 'publication.post_platform_id')
            ->when($labelIds !== [] || $untagged, fn (Builder $query): Builder => $query->where(fn (Builder $filtered): Builder => $filtered
                ->when($labelIds !== [], fn (Builder $any): Builder => $any->whereExists(fn (Builder $labelled): Builder => $labels($labelled)
                    ->whereIn("{$labelPivot}.workspace_label_id", $labelIds)))
                ->when($untagged, fn (Builder $none): Builder => $none->orWhereNotExists($labels))))
            ->select([
                'publication.id', 'publication.social_account_key', 'publication.social_account_id',
                'publication.post_platform_id', 'destination.post_id', 'publication.platform', 'publication.network',
                'publication.account_display_name', 'publication.account_username',
                'publication.account_avatar_url', 'publication.remote_id',
                'publication.provider_published_at', 'publication.origin', 'publication.content_type',
                'publication.availability',
                'publication.permalink', 'publication.excerpt', 'publication.preview_metadata',
                'metric.reactions_count', 'metric.comments_count', 'metric.shares_count',
                'metric.saves_count', 'metric.views_count', 'metric.impressions_count',
                'metric.reach_count', 'metric.engagement_count', 'metric.exposure_count',
                'metric.exposure_kind', 'metric.collected_at',
                'metric.watch_time_milliseconds', 'metric.average_watch_time_milliseconds', 'metric.metrics',
            ]);
    }

    /** @return array{posts: int, reactions: int, comments: int, engagement: int, exposure: int, views: int, reach: int, shares: int, saves: int, impressions: int, clicks: int, reposts: int, watch_time: int, average_watch_time: int, average_watch_time_posts: int, follows: int, has_reactions: bool, has_comments: bool, has_views: bool, has_reach: bool, has_shares: bool, has_saves: bool, has_impressions: bool, has_clicks: bool, has_reposts: bool, has_watch_time: bool, has_follows: bool} */
    private function emptyTotals(): array
    {
        return [
            'posts' => 0,
            'reactions' => 0,
            'comments' => 0,
            'engagement' => 0,
            'exposure' => 0,
            'views' => 0,
            'reach' => 0,
            'shares' => 0,
            'saves' => 0,
            'impressions' => 0,
            'clicks' => 0,
            'reposts' => 0,
            'watch_time' => 0,
            'average_watch_time' => 0,
            'average_watch_time_posts' => 0,
            'follows' => 0,
            'has_reactions' => false,
            'has_comments' => false,
            'has_views' => false,
            'has_reach' => false,
            'has_shares' => false,
            'has_saves' => false,
            'has_impressions' => false,
            'has_clicks' => false,
            'has_reposts' => false,
            'has_watch_time' => false,
            'has_follows' => false,
        ];
    }

    /**
     * @param  array{posts: int, reactions: int, comments: int, engagement: int, exposure: int, views: int, reach: int, shares: int, saves: int, impressions: int, clicks: int, reposts: int, watch_time: int, average_watch_time: int, average_watch_time_posts: int, follows: int, has_reactions: bool, has_comments: bool, has_views: bool, has_reach: bool, has_shares: bool, has_saves: bool, has_impressions: bool, has_clicks: bool, has_reposts: bool, has_watch_time: bool, has_follows: bool}  $totals
     * @return array{posts: int, reactions: int, comments: int, engagement: int, exposure: int, views: int, reach: int, shares: int, saves: int, impressions: int, clicks: int, reposts: int, watch_time: int, average_watch_time: int, average_watch_time_posts: int, follows: int, has_reactions: bool, has_comments: bool, has_views: bool, has_reach: bool, has_shares: bool, has_saves: bool, has_impressions: bool, has_clicks: bool, has_reposts: bool, has_watch_time: bool, has_follows: bool}
     */
    private function addTotals(array $totals, object $row): array
    {
        $totals['posts'] = data_get($totals, 'posts') + 1;
        $measured = $row->metrics === null ? [] : (array) json_decode((string) $row->metrics, true);

        foreach ([
            'reactions' => $row->reactions_count,
            'comments' => $row->comments_count,
            'views' => $row->views_count,
            'reach' => $row->reach_count,
            'shares' => $row->shares_count,
            'saves' => $row->saves_count,
            'impressions' => $row->impressions_count,
            'clicks' => $this->measured($measured, MetricKey::Clicks) ?? $this->measured($measured, MetricKey::LinkClicks),
            'reposts' => $this->measured($measured, MetricKey::Reposts),
            'watch_time' => $row->watch_time_milliseconds,
            'follows' => $this->measured($measured, MetricKey::Follows),
        ] as $metric => $value) {
            if ($value !== null) {
                $totals["has_{$metric}"] = true;
                $totals[$metric] = data_get($totals, $metric) + (int) $value;
            }
        }

        if ($row->average_watch_time_milliseconds !== null) {
            $totals['average_watch_time'] = data_get($totals, 'average_watch_time') + (int) $row->average_watch_time_milliseconds;
            $totals['average_watch_time_posts'] = data_get($totals, 'average_watch_time_posts') + 1;
        }

        if ($row->engagement_count !== null && $row->exposure_count !== null && (int) $row->exposure_count > 0) {
            $totals['engagement'] = data_get($totals, 'engagement') + (int) $row->engagement_count;
            $totals['exposure'] = data_get($totals, 'exposure') + (int) $row->exposure_count;
        }

        return $totals;
    }

    /** @param array<string, mixed> $metrics */
    private function measured(array $metrics, MetricKey $key): ?int
    {
        $metric = data_get($metrics, $key->value);
        $value = data_get($metric, 'value');

        return data_get($metric, 'availability') === MetricAvailability::Available->value && is_numeric($value)
            ? (int) $value : null;
    }

    /**
     * @param  array{posts: int, reactions: int, comments: int, engagement: int, exposure: int, views: int, reach: int, shares: int, saves: int, impressions: int, clicks: int, reposts: int, watch_time: int, average_watch_time: int, average_watch_time_posts: int, follows: int, has_reactions: bool, has_comments: bool, has_views: bool, has_reach: bool, has_shares: bool, has_saves: bool, has_impressions: bool, has_clicks: bool, has_reposts: bool, has_watch_time: bool, has_follows: bool}  $totals
     * @return array{posts: int, reactions: ?int, comments: ?int, engagement_rate: ?float, views: ?int, reach: ?int, shares: ?int, saves: ?int, impressions: ?int, clicks: ?int, reposts: ?int, watch_time_minutes: ?float, average_watch_time_seconds: ?float, follows_gained: ?int}
     */
    private function finalizeTotals(array $totals): array
    {
        $averagePosts = data_get($totals, 'average_watch_time_posts');

        return [
            'posts' => data_get($totals, 'posts'),
            'reactions' => data_get($totals, 'has_reactions') ? data_get($totals, 'reactions') : null,
            'comments' => data_get($totals, 'has_comments') ? data_get($totals, 'comments') : null,
            'engagement_rate' => data_get($totals, 'exposure') === 0 ? null : round(data_get($totals, 'engagement') / data_get($totals, 'exposure') * 100, 2),
            'views' => data_get($totals, 'has_views') ? data_get($totals, 'views') : null,
            'reach' => data_get($totals, 'has_reach') ? data_get($totals, 'reach') : null,
            'shares' => data_get($totals, 'has_shares') ? data_get($totals, 'shares') : null,
            'saves' => data_get($totals, 'has_saves') ? data_get($totals, 'saves') : null,
            'impressions' => data_get($totals, 'has_impressions') ? data_get($totals, 'impressions') : null,
            'clicks' => data_get($totals, 'has_clicks') ? data_get($totals, 'clicks') : null,
            'reposts' => data_get($totals, 'has_reposts') ? data_get($totals, 'reposts') : null,
            'watch_time_minutes' => data_get($totals, 'has_watch_time') ? round(data_get($totals, 'watch_time') / 60000, 2) : null,
            'average_watch_time_seconds' => $averagePosts === 0 ? null : round(data_get($totals, 'average_watch_time') / $averagePosts / 1000, 2),
            'follows_gained' => data_get($totals, 'has_follows') ? data_get($totals, 'follows') : null,
        ];
    }

    /** @param list<object> $rows */
    private function retainTopPublication(array &$rows, object $row, string $metric): void
    {
        if ($row->{$metric} === null) {
            return;
        }

        $rows[] = $row;
        usort($rows, fn (object $a, object $b): int => ((int) $b->{$metric} <=> (int) $a->{$metric})
            ?: strcmp((string) $b->provider_published_at, (string) $a->provider_published_at)
            ?: strcmp((string) $a->id, (string) $b->id));

        if (count($rows) > 5) {
            array_pop($rows);
        }
    }

    /** @return list<array<string, mixed>> */
    private function top(array $rows): array
    {
        return array_map(function (object $row): array {
            return [
                'id' => $row->id,
                'post_platform_id' => $row->post_platform_id,
                'post_id' => $row->post_id,
                'social_account_key' => $row->social_account_key,
                'platform' => $row->platform,
                'name' => $row->account_display_name,
                'username' => $row->account_username,
                'avatar_url' => $row->account_avatar_url,
                'origin' => $row->origin,
                'content_type' => $row->content_type,
                'availability' => $row->availability,
                'published_at' => $row->provider_published_at,
                'permalink' => $row->permalink,
                'excerpt' => $row->excerpt,
                'preview_metadata' => $row->preview_metadata ? json_decode((string) $row->preview_metadata, true) : null,
                'reactions' => $row->reactions_count === null ? null : (int) $row->reactions_count,
                'comments' => $row->comments_count === null ? null : (int) $row->comments_count,
            ];
        }, $rows);
    }

    /** @return list<array<string, mixed>> */
    private function performance(array $current, array $previous): array
    {
        $rows = [];

        foreach ($current as $key => $account) {
            $representative = data_get($account, 'row');
            $totals = $this->finalizeTotals(data_get($account, 'totals'));
            $prior = $this->finalizeTotals(data_get($previous, $key, $this->emptyTotals()));
            $rows[] = [
                'social_account_key' => $key,
                'platform' => $representative->platform,
                'name' => $representative->account_display_name,
                'username' => $representative->account_username,
                'avatar_url' => $representative->account_avatar_url,
                ...array_combine(self::PERFORMANCE_METRICS, array_map(
                    fn (string $metric): array => MetricComparison::between(data_get($totals, $metric), data_get($prior, $metric)),
                    self::PERFORMANCE_METRICS,
                )),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [data_get($a, 'platform'), data_get($a, 'username'), data_get($a, 'social_account_key')]
            <=> [data_get($b, 'platform'), data_get($b, 'username'), data_get($b, 'social_account_key')]);

        return $rows;
    }

    private function inRange(string $date, DateRange $range): bool
    {
        $day = substr($date, 0, 10);

        return $day >= $range->start->toDateString() && $day <= $range->observedThrough->toDateString();
    }
}
