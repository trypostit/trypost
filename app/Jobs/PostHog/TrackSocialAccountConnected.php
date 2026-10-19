<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\SocialAccountEvent;
use App\Models\SocialAccount;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackSocialAccountConnected implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $socialAccountId,
        public string $userId,
        public string $source,
    ) {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $socialAccount = SocialAccount::query()
            ->with('workspace.account.plan')
            ->find($this->socialAccountId);

        $account = $socialAccount?->workspace?->account;

        if (blank($account)) {
            return;
        }

        $nthChannel = $account->socialAccounts()
            ->where(fn (Builder $query) => $query
                ->where('social_accounts.created_at', '<', $socialAccount->created_at)
                ->orWhere(fn (Builder $tied) => $tied
                    ->where('social_accounts.created_at', $socialAccount->created_at)
                    ->where('social_accounts.id', '<=', $socialAccount->id)))
            ->count();

        $postHog->capture(
            $this->userId,
            SocialAccountEvent::Connected->value,
            [
                'social_account_id' => (string) $socialAccount->id,
                'platform' => $socialAccount->platform->value,
                'network' => $socialAccount->platform->network(),
                'workspace_id' => (string) $socialAccount->workspace_id,
                'source' => $this->source,
                'nth_channel' => $nthChannel,
            ],
            $account,
        );
    }
}
