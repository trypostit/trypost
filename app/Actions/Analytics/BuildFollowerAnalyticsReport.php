<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DateRange;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuildFollowerAnalyticsReport
{
    /** @return array{current_total: ?int, previous_total: ?int, followers: array<string, mixed>} */
    public function execute(Workspace $workspace, DateRange $previous, DateRange $current): array
    {
        $rows = $this->rows($workspace, $previous->end, $current);
        $connectedAccounts = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->connected()
            ->active()
            ->includedInAnalytics()
            ->where('created_at', '<=', $current->end->endOfDay())
            ->get(['platform', 'platform_user_id', 'created_at']);
        $currentTotal = $this->total($rows, $current->end, $connectedAccounts);
        $previousTotal = $this->total($rows, $previous->end, $connectedAccounts);

        return [
            'current_total' => $currentTotal,
            'previous_total' => $previousTotal,
            'followers' => $this->followers($rows, $current, $currentTotal),
        ];
    }

    private function rows(Workspace $workspace, CarbonImmutable $previousEnd, DateRange $range): Collection
    {
        return DB::table('analytics_account_daily_snapshots')
            ->where('workspace_id', $workspace->id)
            ->whereIn('platform', Platform::analyticsValues())
            ->where(function ($query) use ($previousEnd, $range): void {
                $query->whereDate('date', $previousEnd->toDateString())
                    ->orWhereBetween('date', [$range->start->toDateString(), $range->end->toDateString()]);
            })
            ->select([
                'social_account_key', 'social_account_id', 'platform', 'network', 'platform_user_id',
                'account_display_name', 'account_username', 'account_avatar_url',
                'date', 'followers_count', 'provenance', 'precision', 'collected_at',
            ])
            ->orderBy('date')
            ->get();
    }

    private function total(Collection $rows, CarbonImmutable $date, Collection $connectedAccounts): ?int
    {
        $onDate = $rows->filter(fn (object $row): bool => substr((string) $row->date, 0, 10) === $date->toDateString()
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
        $current = $rows->filter(fn (object $row): bool => substr((string) $row->date, 0, 10) >= $range->start->toDateString()
            && substr((string) $row->date, 0, 10) <= $range->end->toDateString());
        $byAccount = $current->groupBy('social_account_key');
        $accounts = [];

        foreach ($byAccount as $key => $values) {
            $first = $values->first();
            $last = $values->last();
            $end = $values->first(fn (object $row): bool => substr((string) $row->date, 0, 10) === $range->end->toDateString());
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
        $byDate = $current->groupBy(fn (object $row): string => substr((string) $row->date, 0, 10));

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
