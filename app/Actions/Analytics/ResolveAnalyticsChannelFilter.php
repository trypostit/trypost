<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\RequestIds;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class ResolveAnalyticsChannelFilter
{
    public function __construct(private readonly ResolveAnalyticsAccountKey $accountKey) {}

    /**
     * @param  Collection<int, mixed>  $requested
     * @return array{channels: list<array{id: string, platform: string, display_label: string, username: ?string, avatar_url: ?string, status: string, analytics_key: string}>, selected: EloquentCollection<int, SocialAccount>, keys: array<string, string>|null}
     */
    public function execute(Workspace $workspace, Collection $requested): array
    {
        $accounts = $workspace->socialAccounts()
            ->includedInAnalytics()
            ->get();
        $keys = $this->accountKey->forMany($accounts);
        $selectedIds = RequestIds::selected($requested, $accounts->pluck('id'));

        return [
            'channels' => $accounts->map(fn (SocialAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'display_label' => $account->display_label,
                'username' => $account->username,
                'avatar_url' => $account->avatar_url,
                'status' => $account->status->value,
                'analytics_key' => $keys[$account->id],
            ])->values()->all(),
            'selected' => $accounts->whereIn('id', $selectedIds)->values(),
            'keys' => match (true) {
                $accounts->isEmpty() => [],
                $selectedIds === [] => null,
                default => array_intersect_key($keys, array_flip($selectedIds)),
            },
        ];
    }
}
