<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Support\Percent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildChannelDistribution
{
    /**
     * How the networks spread, read three ways, each network counted once
     * whatever way it connects (Instagram directly and through Facebook, a
     * LinkedIn profile and page):
     *
     * - channels: the connected channels on each network;
     * - accounts: the accounts with a connected channel there (an account
     *   may use several networks, so the shares add up to more than 100%),
     *   and how many channels each of them has there on average;
     * - first: the network of each account's oldest channel still kept,
     *   connected or not (a removed channel is deleted, so an account whose
     *   first channel is gone counts the next one).
     *
     * @return array{
     *     totals: array{channels: int, accounts: int, first: int},
     *     channels: list<array{key: string, label: string, platform: string, count: int, share: float}>,
     *     accounts: list<array{key: string, label: string, platform: string, count: int, share: float, per_account: float}>,
     *     first: list<array{key: string, label: string, platform: string, count: int, share: float}>
     * }
     */
    public static function execute(): array
    {
        $groups = SocialAccount::query()
            ->join('workspaces', 'workspaces.id', '=', 'social_accounts.workspace_id')
            ->tallyBy(['workspaces.account_id', 'social_accounts.platform', 'social_accounts.status'])
            ->withEarliest('social_accounts.created_at', 'first_created_at')
            ->toBase()
            ->get()
            ->map(fn (object $group): array => [
                'account_id' => (string) $group->account_id,
                'network' => Platform::from((string) $group->platform)->network(),
                'connected' => $group->status === Status::Connected->value,
                'total' => (int) $group->total,
                'first_created_at' => CarbonImmutable::parse($group->first_created_at, 'UTC'),
            ]);

        $connected = $groups->where('connected', true);
        $channelCounts = $connected->groupBy('network')->map(fn (Collection $rows): int => (int) $rows->sum('total'));
        $accountCounts = $connected->groupBy('network')->map(fn (Collection $rows): int => $rows->unique('account_id')->count());
        $accountsWithChannel = $connected->unique('account_id')->count();
        $firstCounts = $groups->sortBy('first_created_at')->unique('account_id')->countBy('network');

        return [
            'totals' => [
                'channels' => (int) $connected->sum('total'),
                'accounts' => $accountsWithChannel,
                'first' => $firstCounts->sum(),
            ],
            'channels' => self::rows($channelCounts, (int) $connected->sum('total')),
            'accounts' => collect(self::rows($accountCounts, $accountsWithChannel))
                ->map(fn (array $row): array => [
                    ...$row,
                    'per_account' => round((int) $channelCounts->get(data_get($row, 'key'), 0) / data_get($row, 'count'), 1),
                ])
                ->all(),
            'first' => self::rows($firstCounts, $firstCounts->sum()),
        ];
    }

    /**
     * @param  Collection<string, int>  $counts
     * @return list<array{key: string, label: string, platform: string, count: int, share: float}>
     */
    private static function rows(Collection $counts, int $whole): array
    {
        return $counts
            ->sortDesc()
            ->map(fn (int $count, string $network): array => [
                'key' => $network,
                'label' => Platform::from($network)->label(),
                'platform' => $network,
                'count' => $count,
                'share' => Percent::of($count, $whole),
            ])
            ->values()
            ->all();
    }
}
