<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Dto\Analytics\DateRange;
use App\Enums\SocialAccount\Platform;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\Analytics\PeriodBuckets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class WorkspaceAnalyticsQuery
{
    public function __construct(private readonly PeriodBuckets $buckets) {}

    /** @return array<string, mixed> */
    public function for(Workspace $workspace, DateRange $range): array
    {
        $previous = $range->previous();
        $publications = $this->publicationReport($workspace, $previous, $range);
        $followers = $this->followerRows($workspace, $previous->end, $range);
        $connectedAccounts = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->connected()
            ->active()
            ->includedInAnalytics()
            ->where('created_at', '<=', $range->end->endOfDay())
            ->get(['platform', 'platform_user_id', 'created_at']);
        $currentFollowers = $this->followerTotal($followers, $range->end, $connectedAccounts);
        $previousFollowers = $this->followerTotal($followers, $previous->end, $connectedAccounts);
        $current = $publications['current_totals'];
        $prior = $publications['previous_totals'];

        return [
            'bounds' => $this->boundsFor($workspace),
            'range' => $range->toArray(),
            'previous_range' => $previous->toArray(),
            'summary' => [
                'posts' => $this->comparison($current['posts'], $prior['posts']),
                'followers' => [
                    'value' => $currentFollowers,
                    'previous' => $previousFollowers,
                    'change' => $currentFollowers !== null && $previousFollowers !== null
                        ? $currentFollowers - $previousFollowers : null,
                ],
                'reactions' => $this->comparison($current['reactions'], $prior['reactions']),
                'comments' => $this->comparison($current['comments'], $prior['comments']),
                'engagement_rate' => $this->comparison($current['engagement_rate'], $prior['engagement_rate']),
            ],
            'followers' => $this->followers($followers, $range, $currentFollowers),
            'posts' => $publications['posts'],
            'top_posts' => $publications['top_posts'],
            'performance' => $publications['performance'],
            'coverage' => $this->coverage($workspace),
        ];
    }

    private function publications(Workspace $workspace, CarbonImmutable $start, CarbonImmutable $end): LazyCollection
    {
        $latest = DB::table('analytics_publication_daily_snapshots as daily')
            ->join('analytics_publications as parent', 'parent.id', '=', 'daily.analytics_publication_id')
            ->where('parent.workspace_id', $workspace->id)
            ->whereIn('parent.platform', Platform::analyticsValues())
            ->whereBetween('parent.provider_published_at', [$start->startOfDay(), $end->endOfDay()])
            ->select('daily.analytics_publication_id')
            ->selectRaw('MAX(daily.snapshot_date) as latest_date')
            ->groupBy('daily.analytics_publication_id');

        return DB::table('analytics_publications as publication')
            ->leftJoin((new PostPlatform)->getTable().' as destination', 'destination.id', '=', 'publication.post_platform_id')
            ->leftJoinSub($latest, 'latest', 'latest.analytics_publication_id', '=', 'publication.id')
            ->leftJoin('analytics_publication_daily_snapshots as metric', function ($join): void {
                $join->on('metric.analytics_publication_id', '=', 'publication.id')
                    ->on('metric.snapshot_date', '=', 'latest.latest_date');
            })
            ->where('publication.workspace_id', $workspace->id)
            ->whereIn('publication.platform', Platform::analyticsValues())
            ->whereBetween('publication.provider_published_at', [$start->startOfDay(), $end->endOfDay()])
            ->select([
                'publication.id', 'publication.social_account_key', 'publication.social_account_id',
                'publication.post_platform_id', 'destination.post_id', 'publication.platform', 'publication.network',
                'publication.account_display_name', 'publication.account_username',
                'publication.account_avatar_url', 'publication.provider_post_id',
                'publication.provider_published_at', 'publication.origin', 'publication.content_type',
                'publication.availability',
                'publication.permalink', 'publication.excerpt', 'publication.preview_metadata',
                'metric.reactions_count', 'metric.comments_count', 'metric.shares_count',
                'metric.saves_count', 'metric.views_count', 'metric.impressions_count',
                'metric.reach_count', 'metric.engagement_count', 'metric.exposure_count',
                'metric.exposure_kind', 'metric.collected_at',
            ])
            ->cursor();
    }

    /** @return array<string, mixed> */
    private function publicationReport(Workspace $workspace, DateRange $previous, DateRange $current): array
    {
        $currentTotals = $this->emptyPublicationTotals();
        $previousTotals = $this->emptyPublicationTotals();
        $currentAccounts = [];
        $previousAccounts = [];
        $topReactions = [];
        $topComments = [];
        $buckets = $this->buckets->for($current);
        $bucketIndexByDate = [];
        $bucketCounts = [];

        foreach ($buckets as $index => $bucket) {
            for ($day = CarbonImmutable::parse($bucket['start'], 'UTC'); $day->toDateString() <= $bucket['end']; $day = $day->addDay()) {
                $bucketIndexByDate[$day->toDateString()] = $index;
            }
        }

        foreach ($this->publications($workspace, $previous->start, $current->end) as $row) {
            $key = $row->social_account_key;

            if (! $this->inRange($row->provider_published_at, $current)) {
                $this->addPublicationTotals($previousTotals, $row);
                $previousAccounts[$key] ??= $this->emptyPublicationTotals();
                $this->addPublicationTotals($previousAccounts[$key], $row);

                continue;
            }

            $this->addPublicationTotals($currentTotals, $row);
            $currentAccounts[$key] ??= ['row' => $row, 'totals' => $this->emptyPublicationTotals()];
            $this->addPublicationTotals($currentAccounts[$key]['totals'], $row);
            $this->retainTopPublication($topReactions, $row, 'reactions_count');
            $this->retainTopPublication($topComments, $row, 'comments_count');

            $date = substr((string) $row->provider_published_at, 0, 10);
            $index = $bucketIndexByDate[$date] ?? null;

            if ($index !== null) {
                $bucketCounts[$index][$key] = ($bucketCounts[$index][$key] ?? 0) + 1;
            }
        }

        $postAccounts = [];

        foreach ($currentAccounts as $key => $account) {
            $row = $account['row'];
            $postAccounts[] = [
                'social_account_key' => $key,
                'platform' => $row->platform,
                'name' => $row->account_display_name,
                'username' => $row->account_username,
                'avatar_url' => $row->account_avatar_url,
                'count' => $account['totals']['posts'],
            ];
        }

        foreach ($buckets as $index => &$bucket) {
            $bucket['accounts'] = array_fill_keys(array_keys($currentAccounts), 0);

            foreach ($bucketCounts[$index] ?? [] as $key => $count) {
                $bucket['accounts'][$key] = $count;
            }

            $bucket['total'] = array_sum($bucket['accounts']);
        }
        unset($bucket);

        return [
            'current_totals' => $this->finalizePublicationTotals($currentTotals),
            'previous_totals' => $this->finalizePublicationTotals($previousTotals),
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

    private function followerRows(Workspace $workspace, CarbonImmutable $previousEnd, DateRange $range): Collection
    {
        return DB::table('analytics_account_daily_snapshots')
            ->where('workspace_id', $workspace->id)
            ->whereIn('platform', Platform::analyticsValues())
            ->where(function ($query) use ($previousEnd, $range): void {
                $query->whereDate('snapshot_date', $previousEnd->toDateString())
                    ->orWhereBetween('snapshot_date', [$range->start->toDateString(), $range->end->toDateString()]);
            })
            ->select([
                'social_account_key', 'social_account_id', 'platform', 'network', 'platform_user_id',
                'account_display_name', 'account_username', 'account_avatar_url',
                'snapshot_date', 'followers_count', 'provenance', 'precision', 'collected_at',
            ])
            ->orderBy('snapshot_date')
            ->get();
    }

    /** @return array{min: ?string, max: ?string} */
    public function boundsFor(Workspace $workspace): array
    {
        $accounts = DB::table('analytics_account_daily_snapshots')
            ->where('workspace_id', $workspace->id)
            ->whereIn('platform', Platform::analyticsValues())
            ->selectRaw('MIN(snapshot_date) as earliest, MAX(snapshot_date) as latest')
            ->first();
        $publications = DB::table('analytics_publications')
            ->where('workspace_id', $workspace->id)
            ->whereIn('platform', Platform::analyticsValues())
            ->selectRaw('MIN(provider_published_at) as earliest, MAX(provider_published_at) as latest')
            ->first();
        $minimum = array_filter([$accounts?->earliest, $publications?->earliest]);
        $maximum = array_filter([$accounts?->latest, $publications?->latest]);

        return [
            'min' => $minimum ? CarbonImmutable::parse(min($minimum), 'UTC')->toDateString() : null,
            'max' => $maximum ? CarbonImmutable::parse(max($maximum), 'UTC')->toDateString() : null,
        ];
    }

    private function followerTotal(Collection $rows, CarbonImmutable $date, Collection $connectedAccounts): ?int
    {
        $onDate = $rows->filter(fn (object $row): bool => substr((string) $row->snapshot_date, 0, 10) === $date->toDateString()
            && $row->followers_count !== null);

        foreach ($connectedAccounts as $account) {
            if (CarbonImmutable::parse($account->created_at, 'UTC')->greaterThan($date->endOfDay())) {
                continue;
            }

            if (! $onDate->contains(fn (object $row): bool => $row->network === $account->platform->network()
                && $row->platform_user_id === $account->platform_user_id)) {
                return null;
            }
        }

        return $onDate->isEmpty() ? null : (int) $onDate->sum('followers_count');
    }

    /** @return array<string, mixed> */
    private function followers(Collection $rows, DateRange $range, ?int $total): array
    {
        $current = $rows->filter(fn (object $row): bool => $this->inRange($row->snapshot_date, $range));
        $byAccount = $current->groupBy('social_account_key');
        $accounts = [];

        foreach ($byAccount as $key => $values) {
            $first = $values->first();
            $last = $values->last();
            $end = $values->first(fn (object $row): bool => substr((string) $row->snapshot_date, 0, 10) === $range->end->toDateString());
            $accounts[] = [
                'social_account_key' => $key,
                'social_account_id' => $last->social_account_id,
                'platform' => $last->platform,
                'network' => $last->network,
                'name' => $last->account_display_name,
                'username' => $last->account_username,
                'avatar_url' => $last->account_avatar_url,
                'value' => $end?->followers_count === null ? null : (int) $end->followers_count,
                'growth' => $values->count() > 1 && $first->followers_count !== null && $last->followers_count !== null
                    ? (int) $last->followers_count - (int) $first->followers_count : null,
                'provenance' => $end?->provenance,
            ];
        }

        usort($accounts, fn (array $a, array $b): int => [$a['platform'], $a['username'], $a['social_account_key']]
            <=> [$b['platform'], $b['username'], $b['social_account_key']]);
        $series = [];
        $byDate = $current->groupBy(fn (object $row): string => substr((string) $row->snapshot_date, 0, 10));

        for ($day = $range->start; $day->lessThanOrEqualTo($range->end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $values = array_fill_keys(array_column($accounts, 'social_account_key'), null);

            foreach ($byDate->get($date, collect()) as $row) {
                $values[$row->social_account_key] = $row->followers_count === null ? null : (int) $row->followers_count;
            }

            $series[] = ['date' => $date, 'accounts' => $values];
        }

        return ['total' => $total, 'accounts' => $accounts, 'series' => $series];
    }

    /** @return array{posts: int, reactions: int, comments: int, engagement: int, exposure: int, has_reactions: bool, has_comments: bool} */
    private function emptyPublicationTotals(): array
    {
        return [
            'posts' => 0,
            'reactions' => 0,
            'comments' => 0,
            'engagement' => 0,
            'exposure' => 0,
            'has_reactions' => false,
            'has_comments' => false,
        ];
    }

    /** @param array<string, int|bool> $totals */
    private function addPublicationTotals(array &$totals, object $row): void
    {
        $totals['posts']++;

        if ($row->reactions_count !== null) {
            $totals['has_reactions'] = true;
            $totals['reactions'] += (int) $row->reactions_count;
        }

        if ($row->comments_count !== null) {
            $totals['has_comments'] = true;
            $totals['comments'] += (int) $row->comments_count;
        }

        if ($row->engagement_count !== null && $row->exposure_count !== null && (int) $row->exposure_count > 0) {
            $totals['engagement'] += (int) $row->engagement_count;
            $totals['exposure'] += (int) $row->exposure_count;
        }
    }

    /**
     * @param  array<string, int|bool>  $totals
     * @return array{posts: int, reactions: ?int, comments: ?int, engagement_rate: ?float}
     */
    private function finalizePublicationTotals(array $totals): array
    {
        return [
            'posts' => $totals['posts'],
            'reactions' => $totals['has_reactions'] ? $totals['reactions'] : null,
            'comments' => $totals['has_comments'] ? $totals['comments'] : null,
            'engagement_rate' => $totals['exposure'] === 0 ? null : round($totals['engagement'] / $totals['exposure'] * 100, 2),
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

    /** @return array{value: int|float|null, previous: int|float|null, change: ?float} */
    private function comparison(int|float|null $current, int|float|null $previous): array
    {
        return [
            'value' => $current,
            'previous' => $previous,
            'change' => $current !== null && $previous !== null && $previous != 0
                ? round(($current - $previous) / $previous * 100, 2) : null,
        ];
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
            $representative = $account['row'];
            $totals = $this->finalizePublicationTotals($account['totals']);
            $prior = $this->finalizePublicationTotals($previous[$key] ?? $this->emptyPublicationTotals());
            $rows[] = [
                'social_account_key' => $key,
                'platform' => $representative->platform,
                'name' => $representative->account_display_name,
                'username' => $representative->account_username,
                'avatar_url' => $representative->account_avatar_url,
                'posts' => $this->comparison($totals['posts'], $prior['posts']),
                'reactions' => $this->comparison($totals['reactions'], $prior['reactions']),
                'comments' => $this->comparison($totals['comments'], $prior['comments']),
                'engagement_rate' => $this->comparison($totals['engagement_rate'], $prior['engagement_rate']),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['platform'], $a['username'], $a['social_account_key']]
            <=> [$b['platform'], $b['username'], $b['social_account_key']]);

        return $rows;
    }

    /** @return list<object> */
    private function coverage(Workspace $workspace): array
    {
        return DB::table('analytics_sync_states as state')
            ->join('social_accounts as account', 'account.id', '=', 'state.social_account_id')
            ->where('account.workspace_id', $workspace->id)
            ->whereIn('account.platform', Platform::analyticsValues())
            ->select([
                'state.social_account_id', 'state.collector', 'state.status',
                'state.target_since', 'state.oldest_reached_at', 'state.high_watermark_at',
                'state.last_success_at', 'state.last_error_category',
            ])
            ->get()
            ->all();
    }

    private function inRange(string $date, DateRange $range): bool
    {
        $day = substr($date, 0, 10);

        return $day >= $range->start->toDateString() && $day <= $range->end->toDateString();
    }
}
