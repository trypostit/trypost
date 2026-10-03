<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ResolveAnalyticsAccountKey
{
    public function for(SocialAccount $account): string
    {
        return $this->forMany(collect([$account]))[$account->id];
    }

    /**
     * Resolves the analytics key of every account with one query per analytics table: the key of the
     * identity's latest daily snapshot, else of its latest publication, else the account id.
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @return array<string, string>
     */
    public function forMany(Collection $accounts): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        $snapshotKeys = $this->latestKeys(AnalyticsAccountDailySnapshot::query(), 'date', $accounts);
        $publicationKeys = $this->latestKeys(AnalyticsPublication::query(), 'provider_published_at', $accounts);

        return $accounts->mapWithKeys(function (SocialAccount $account) use ($snapshotKeys, $publicationKeys): array {
            $identity = $this->identity($account->workspace_id, $account->platform->network(), $account->platform_user_id);

            return [$account->id => $snapshotKeys[$identity] ?? $publicationKeys[$identity] ?? $account->id];
        })->all();
    }

    /**
     * @param  Builder<AnalyticsAccountDailySnapshot|AnalyticsPublication>  $query
     * @param  Collection<int, SocialAccount>  $accounts
     * @return array<string, string>
     */
    private function latestKeys(Builder $query, string $dateColumn, Collection $accounts): array
    {
        $latest = [];

        $query->toBase()
            ->whereIn('workspace_id', $accounts->pluck('workspace_id')->unique()->values()->all())
            ->whereIn('network', $accounts->map(fn (SocialAccount $account): string => $account->platform->network())->unique()->values()->all())
            ->whereIn('platform_user_id', $accounts->pluck('platform_user_id')->unique()->values()->all())
            ->whereNotNull($dateColumn)
            ->select(['workspace_id', 'network', 'platform_user_id', 'social_account_key'])
            ->selectRaw("MAX({$dateColumn}) as latest_at")
            ->groupBy(['workspace_id', 'network', 'platform_user_id', 'social_account_key'])
            ->get()
            ->sortBy([['latest_at', 'asc'], ['social_account_key', 'asc']])
            ->each(function (object $row) use (&$latest): void {
                $latest[$this->identity($row->workspace_id, $row->network, $row->platform_user_id)] = $row->social_account_key;
            });

        return $latest;
    }

    private function identity(string $workspaceId, string $network, string $platformUserId): string
    {
        return "{$workspaceId}|{$network}|{$platformUserId}";
    }
}
