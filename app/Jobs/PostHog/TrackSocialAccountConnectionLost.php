<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\SocialAccountEvent;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The channel stayed in the workspace but lost its connection (expired token,
 * failed verification). Nobody acted, so the event goes to the account owner.
 */
class TrackSocialAccountConnectionLost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $socialAccountId,
        public Status $status,
    ) {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $socialAccount = SocialAccount::query()->with('workspace.account.plan')->find($this->socialAccountId);
        $account = $socialAccount?->workspace?->account;

        if (blank($account?->owner_id)) {
            return;
        }

        $postHog->capture(
            (string) $account->owner_id,
            SocialAccountEvent::Disconnected->value,
            [
                'social_account_id' => (string) $socialAccount->id,
                'platform' => $socialAccount->platform->value,
                'network' => $socialAccount->platform->network(),
                'workspace_id' => (string) $socialAccount->workspace_id,
                'reason' => $this->status->value,
                'error_type' => $this->status->value,
            ],
            $account,
        );
    }
}
