<?php

declare(strict_types=1);

namespace App\Actions\Admin;

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
        $channels = SocialAccount::query()
            ->withoutGlobalScopes()
            ->join('workspaces', 'workspaces.id', '=', 'social_accounts.workspace_id')
            ->toBase()
            ->get(['workspaces.account_id', 'social_accounts.platform', 'social_accounts.status', 'social_accounts.created_at'])
            ->map(fn (object $channel): array => [
                'account_id' => (string) $channel->account_id,
                'network' => Platform::from((string) $channel->platform)->network(),
                'connected' => $channel->status === Status::Connected->value,
                'created_at' => CarbonImmutable::parse($channel->created_at),
            ]);

        $connected = $channels->where('connected', true);
        $channelCounts = $connected->countBy('network');
        $accountCounts = $connected->groupBy('network')->map(fn (Collection $rows): int => $rows->unique('account_id')->count());
        $accountsWithChannel = $connected->unique('account_id')->count();
        $firstCounts = $channels->sortBy('created_at')->unique('account_id')->countBy('network');

        return [
            'totals' => [
                'channels' => $connected->count(),
                'accounts' => $accountsWithChannel,
                'first' => $firstCounts->sum(),
            ],
            'channels' => self::rows($channelCounts, $connected->count()),
            'accounts' => collect(self::rows($accountCounts, $accountsWithChannel))
                ->map(fn (array $row): array => [
                    ...$row,
                    'per_account' => round((int) $channelCounts->get($row['key'], 0) / $row['count'], 1),
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
