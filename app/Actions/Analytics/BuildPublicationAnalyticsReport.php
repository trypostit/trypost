<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DateRange;
use App\Enums\SocialAccount\Platform;
use App\Models\PostPlatform;
use App\Models\Workspace;
use App\Support\Analytics\MetricComparison;
use App\Support\Analytics\PeriodBuckets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class BuildPublicationAnalyticsReport
{
    public function __construct(private readonly PeriodBuckets $buckets) {}

    /** @return array<string, mixed> */
    public function execute(Workspace $workspace, DateRange $previous, DateRange $current): array
    {
        $currentTotals = $this->emptyTotals();
        $previousTotals = $this->emptyTotals();
        $currentAccounts = [];
        $previousAccounts = [];
        $topReactions = [];
        $topComments = [];
        $buckets = $this->buckets->for($current);
        $bucketIndexByDate = [];
        $bucketCounts = [];

        foreach ($buckets as $index => $bucket) {
            for ($day = CarbonImmutable::parse(data_get($bucket, 'start'), 'UTC'); $day->toDateString() <= data_get($bucket, 'end'); $day = $day->addDay()) {
                $bucketIndexByDate[$day->toDateString()] = $index;
            }
        }

        foreach ($this->publications($workspace, $previous->start, $current->end) as $row) {
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

            $date = substr((string) $row->provider_published_at, 0, 10);
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

    private function publications(Workspace $workspace, CarbonImmutable $start, CarbonImmutable $end): LazyCollection
    {
        $latest = DB::table('analytics_publication_daily_snapshots as daily')
            ->join('analytics_publications as parent', 'parent.id', '=', 'daily.publication_id')
            ->where('parent.workspace_id', $workspace->id)
            ->whereIn('parent.platform', Platform::analyticsValues())
            ->whereBetween('parent.provider_published_at', [$start->startOfDay(), $end->endOfDay()])
            ->select('daily.publication_id')
            ->selectRaw('MAX(daily.date) as latest_date')
            ->groupBy('daily.publication_id');

        return DB::table('analytics_publications as publication')
            ->leftJoin((new PostPlatform)->getTable().' as destination', 'destination.id', '=', 'publication.post_platform_id')
            ->leftJoinSub($latest, 'latest', 'latest.publication_id', '=', 'publication.id')
            ->leftJoin('analytics_publication_daily_snapshots as metric', function ($join): void {
                $join->on('metric.publication_id', '=', 'publication.id')
                    ->on('metric.date', '=', 'latest.latest_date');
            })
            ->where('publication.workspace_id', $workspace->id)
            ->whereIn('publication.platform', Platform::analyticsValues())
            ->whereBetween('publication.provider_published_at', [$start->startOfDay(), $end->endOfDay()])
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
            ])
            ->cursor();
    }

    /** @return array{posts: int, reactions: int, comments: int, engagement: int, exposure: int, has_reactions: bool, has_comments: bool} */
    private function emptyTotals(): array
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

    /**
     * @param  array{posts: int, reactions: int, comments: int, engagement: int, exposure: int, has_reactions: bool, has_comments: bool}  $totals
     * @return array{posts: int, reactions: int, comments: int, engagement: int, exposure: int, has_reactions: bool, has_comments: bool}
     */
    private function addTotals(array $totals, object $row): array
    {
        $totals['posts'] = data_get($totals, 'posts') + 1;

        if ($row->reactions_count !== null) {
            $totals['has_reactions'] = true;
            $totals['reactions'] = data_get($totals, 'reactions') + (int) $row->reactions_count;
        }

        if ($row->comments_count !== null) {
            $totals['has_comments'] = true;
            $totals['comments'] = data_get($totals, 'comments') + (int) $row->comments_count;
        }

        if ($row->engagement_count !== null && $row->exposure_count !== null && (int) $row->exposure_count > 0) {
            $totals['engagement'] = data_get($totals, 'engagement') + (int) $row->engagement_count;
            $totals['exposure'] = data_get($totals, 'exposure') + (int) $row->exposure_count;
        }

        return $totals;
    }

    /**
     * @param  array<string, int|bool>  $totals
     * @return array{posts: int, reactions: ?int, comments: ?int, engagement_rate: ?float}
     */
    private function finalizeTotals(array $totals): array
    {
        return [
            'posts' => data_get($totals, 'posts'),
            'reactions' => data_get($totals, 'has_reactions') ? data_get($totals, 'reactions') : null,
            'comments' => data_get($totals, 'has_comments') ? data_get($totals, 'comments') : null,
            'engagement_rate' => data_get($totals, 'exposure') === 0 ? null : round(data_get($totals, 'engagement') / data_get($totals, 'exposure') * 100, 2),
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
                'posts' => MetricComparison::between(data_get($totals, 'posts'), data_get($prior, 'posts')),
                'reactions' => MetricComparison::between(data_get($totals, 'reactions'), data_get($prior, 'reactions')),
                'comments' => MetricComparison::between(data_get($totals, 'comments'), data_get($prior, 'comments')),
                'engagement_rate' => MetricComparison::between(data_get($totals, 'engagement_rate'), data_get($prior, 'engagement_rate')),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [data_get($a, 'platform'), data_get($a, 'username'), data_get($a, 'social_account_key')]
            <=> [data_get($b, 'platform'), data_get($b, 'username'), data_get($b, 'social_account_key')]);

        return $rows;
    }

    private function inRange(string $date, DateRange $range): bool
    {
        $day = substr($date, 0, 10);

        return $day >= $range->start->toDateString() && $day <= $range->end->toDateString();
    }
}
