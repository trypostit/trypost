<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\SocialAccount\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class QueryLatestPublicationSnapshots
{
    /** @param  list<string>|null  $accountKeys  Analytics account keys to scope to; null means every account. */
    public function execute(string $workspaceId, ?array $accountKeys = null, ?CarbonImmutable $start = null, ?CarbonImmutable $end = null): Builder
    {
        $latest = $this->scope(DB::table('analytics_publication_daily_snapshots as daily')
            ->join('analytics_publications as parent', 'parent.id', '=', 'daily.publication_id'), 'parent', $workspaceId, $accountKeys, $start, $end)
            ->select('daily.publication_id')
            ->selectRaw('MAX(daily.date) as latest_date')
            ->groupBy('daily.publication_id');

        return $this->scope(DB::table('analytics_publications as publication'), 'publication', $workspaceId, $accountKeys, $start, $end)
            ->leftJoinSub($latest, 'latest', 'latest.publication_id', '=', 'publication.id')
            ->leftJoin('analytics_publication_daily_snapshots as metric', fn (JoinClause $join): JoinClause => $join
                ->on('metric.publication_id', '=', 'publication.id')
                ->on('metric.date', '=', 'latest.latest_date'));
    }

    /** @param  list<string>|null  $accountKeys */
    private function scope(Builder $query, string $alias, string $workspaceId, ?array $accountKeys, ?CarbonImmutable $start, ?CarbonImmutable $end): Builder
    {
        return $query
            ->where("{$alias}.workspace_id", $workspaceId)
            ->whereIn("{$alias}.platform", Platform::analyticsValues())
            ->when($start !== null && $end !== null, fn (Builder $scoped): Builder => $scoped
                ->whereBetween("{$alias}.provider_published_at", [$start->startOfDay(), $end->endOfDay()]))
            ->when($accountKeys !== null, fn (Builder $scoped): Builder => $scoped->whereIn("{$alias}.social_account_key", $accountKeys));
    }
}
