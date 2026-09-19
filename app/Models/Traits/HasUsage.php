<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\Account;
use App\Models\Invite;
use App\Models\Post;
use App\Support\BillingCycle;
use Illuminate\Support\Facades\Cache;

trait HasUsage
{
    private const POST_COUNT_CACHE_TTL = 300;

    /**
     * @return array{workspaceCount: int, socialAccountCount: int, memberCount: int, pendingInviteCount: int, postCount: int, creditsUsed: int}
     */
    public function usage(): array
    {
        $workspaces = $this->workspaces()
            ->withCount('socialAccounts')
            ->get();

        return [
            'workspaceCount' => $workspaces->count(),
            'socialAccountCount' => (int) $workspaces->sum('social_accounts_count'),
            'memberCount' => $this->users()->count(),
            'pendingInviteCount' => Invite::where('account_id', $this->id)
                ->whereNull('accepted_at')
                ->count(),
            'postCount' => $this->cachedPostCount($workspaces->pluck('id')->all()),
            'creditsUsed' => BillingCycle::for($this)->usedCredits(),
        ];
    }

    /**
     * @return array{workspaceLimit: int|null}
     */
    public function featureLimits(): array
    {
        return ['workspaceLimit' => $this->workspaceLimit()];
    }

    /**
     * The `(int)` cast is load-bearing: Laravel's RedisStore stores numeric
     * values raw (for atomic INCR) and returns them as strings on read.
     *
     * @param  array<int, string>  $workspaceIds
     */
    private function cachedPostCount(array $workspaceIds): int
    {
        if (empty($workspaceIds)) {
            return 0;
        }

        return (int) Cache::remember(
            Account::postsCountCacheKey((string) $this->id),
            self::POST_COUNT_CACHE_TTL,
            fn () => Post::whereIn('workspace_id', $workspaceIds)->count(),
        );
    }
}
