<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Models\Account;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class SyncAccountUsage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $accountId, public ?string $workspaceId = null)
    {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        Cache::forget(Account::postsCountCacheKey($this->accountId));

        $account = Account::with('plan')->find($this->accountId);

        if (! $account) {
            return;
        }

        $usage = $account->usage();

        $postHog->groupIdentify('account', (string) $account->id, [
            'name' => $account->name,
            'plan' => $account->plan?->name,
            'plan_slug' => $account->plan?->slug->value,
            'has_active_subscription' => $account->hasActiveSubscription(),
            'is_on_trial' => $account->isOnTrial(),
            'workspaces_count' => $usage['workspaceCount'],
            'members_count' => $usage['memberCount'],
            'social_accounts_count' => $usage['socialAccountCount'],
            'posts_count' => $usage['postCount'],
            'pending_invites_count' => $usage['pendingInviteCount'],
            'credits_used' => $usage['creditsUsed'],
            'created_at' => $account->created_at?->toIso8601String(),
        ]);

        if (! $this->workspaceId) {
            return;
        }

        $workspace = Workspace::withCount('socialAccounts')->find($this->workspaceId);

        if (! $workspace) {
            return;
        }

        $postHog->groupIdentify('workspace', (string) $workspace->id, [
            'name' => $workspace->name,
            'account_id' => (string) $workspace->account_id,
            'social_accounts_count' => (int) $workspace->social_accounts_count,
            'created_at' => $workspace->created_at?->toIso8601String(),
        ]);
    }
}
