<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DateRange;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuildFollowerAnalyticsReport
{
    private const array COLUMNS = [
        'social_account_key', 'social_account_id', 'platform', 'network', 'platform_user_id',
        'account_display_name', 'account_username', 'account_avatar_url',
        'date', 'followers_count', 'provenance', 'precision', 'collected_at',
    ];

    /**
     * @param  array<string, string>|null  $channelKeys  Analytics key of each selected social account, keyed by account id; null means every account.
     * @return array{current_total: ?int, previous_total: ?int, followers: array<string, mixed>}
     */
    public function execute(Workspace $workspace, DateRange $previous, DateRange $current, ?array $channelKeys = null): array
    {
        $accountKeys = $channelKeys === null ? null : array_values(array_unique($channelKeys));
        $rows = $this->rows($workspace, $current, $accountKeys);
        $latestCurrent = $this->latest($workspace, $current, $accountKeys);
        $connectedAccounts = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->when($channelKeys !== null, fn (EloquentBuilder $accounts): EloquentBuilder => $accounts->whereKey(array_keys($channelKeys)))
            ->connected()
            ->includedInAnalytics()
            ->where('created_at', '<=', $current->observedThrough->endOfDay())
            ->get(['platform', 'platform_user_id', 'created_at']);
        $currentTotal = $this->total($latestCurrent, $current->observedThrough, $connectedAccounts);
        $previousTotal = $this->total($this->latest($workspace, $previous, $accountKeys), $previous->observedThrough, $connectedAccounts);

        return [
            'current_total' => $currentTotal,
            'previous_total' => $previousTotal,
            'followers' => $this->followers($rows, $latestCurrent, $current, $currentTotal),
        ];
    }

    /** @param  list<string>|null  $accountKeys */
    private function rows(Workspace $workspace, DateRange $range, ?array $accountKeys): Collection
    {
        return $this->snapshots($workspace, $range, $accountKeys)
            ->select(self::COLUMNS)
            ->orderBy('date')
            ->get();
    }

    /** @param  list<string>|null  $accountKeys */
    private function latest(Workspace $workspace, DateRange $range, ?array $accountKeys): Collection
    {
        $latestDates = $this->snapshots($workspace, $range, $accountKeys)
            ->whereNotNull('followers_count')
            ->select('social_account_key')
            ->selectRaw('MAX(date) as latest_date')
            ->groupBy('social_account_key');

        return $this->snapshots($workspace, $range, $accountKeys)
            ->joinSub($latestDates, 'latest', fn (JoinClause $join): JoinClause => $join
                ->on('analytics_account_daily_snapshots.social_account_key', '=', 'latest.social_account_key')
                ->on('analytics_account_daily_snapshots.date', '=', 'latest.latest_date'))
            ->select(array_map(fn (string $column): string => "analytics_account_daily_snapshots.{$column}", self::COLUMNS))
            ->get()
            ->keyBy('social_account_key');
    }

    /** @param  list<string>|null  $accountKeys */
    private function snapshots(Workspace $workspace, DateRange $range, ?array $accountKeys): Builder
    {
        return DB::table('analytics_account_daily_snapshots')
            ->where('analytics_account_daily_snapshots.workspace_id', $workspace->id)
            ->whereIn('analytics_account_daily_snapshots.platform', Platform::analyticsValues())
            ->when($accountKeys !== null, fn (Builder $query): Builder => $query->whereIn('analytics_account_daily_snapshots.social_account_key', $accountKeys))
            ->whereBetween('analytics_account_daily_snapshots.date', [$range->start->toDateString(), $range->observedThrough->toDateString()]);
    }

    private function total(Collection $latest, CarbonImmutable $date, Collection $connectedAccounts): ?int
    {
        foreach ($connectedAccounts as $account) {
            if (CarbonImmutable::parse($account->created_at, 'UTC')->greaterThan($date->endOfDay())) {
                continue;
            }

            if (! $latest->contains(fn (object $row): bool => $row->network === $account->platform->network()
                && $row->platform_user_id === $account->platform_user_id)) {
                return null;
            }
        }

        return $latest->isEmpty() ? null : (int) $latest->sum('followers_count');
    }

    /** @return array<string, mixed> */
    private function followers(Collection $current, Collection $latest, DateRange $range, ?int $total): array
    {
        $byAccount = $current->groupBy('social_account_key');
        $accounts = [];

        foreach ($byAccount as $key => $values) {
            $first = $values->first();
            $last = $values->last();
            $end = $latest->get($key);
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

        usort($accounts, fn (array $a, array $b): int => [data_get($a, 'platform'), data_get($a, 'username'), data_get($a, 'social_account_key')]
            <=> [data_get($b, 'platform'), data_get($b, 'username'), data_get($b, 'social_account_key')]);
        $series = [];
        $byDate = $current->groupBy(fn (object $row): string => min(substr((string) $row->date, 0, 10), $range->end->toDateString()));

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
}
