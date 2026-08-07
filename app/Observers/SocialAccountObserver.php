<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Events\OnboardingStatusUpdated;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\SocialAccount;
use App\Services\PostHogService;

class SocialAccountObserver
{
    /**
     * Enforce one connected account per social network per workspace. Variants
     * of the same network (LinkedIn profile/page, Instagram standalone/Facebook)
     * collapse via Platform::network(). Reconnecting an existing account goes
     * through updateOrCreate's update path and never reaches this hook. Bypassed
     * in self-hosted mode, which has no per-workspace limits.
     */
    public function creating(SocialAccount $socialAccount): void
    {
        if (config('trypost.self_hosted') || ! $socialAccount->platform instanceof Platform) {
            return;
        }

        $conflict = SocialAccount::query()
            ->where('workspace_id', $socialAccount->workspace_id)
            ->whereIn('platform', $socialAccount->platform->networkPlatformValues())
            ->exists();

        if ($conflict) {
            throw new NetworkAlreadyConnectedException($socialAccount->platform);
        }
    }

    public function created(SocialAccount $socialAccount): void
    {
        $this->syncUsageAndOnboarding($socialAccount);
    }

    public function deleted(SocialAccount $socialAccount): void
    {
        $this->syncUsageAndOnboarding($socialAccount);
    }

    public function updated(SocialAccount $socialAccount): void
    {
        if (! $socialAccount->wasChanged('status')) {
            return;
        }

        $wasConnected = $socialAccount->getRawOriginal('status') === Status::Connected->value;
        $isConnected = $socialAccount->status === Status::Connected;

        if ($wasConnected !== $isConnected) {
            $this->notifyOnboarding($socialAccount);
        }
    }

    private function syncUsageAndOnboarding(SocialAccount $socialAccount): void
    {
        $this->syncUsage($socialAccount);

        if ($socialAccount->status === Status::Connected) {
            $this->notifyOnboarding($socialAccount);
        }
    }

    /**
     * First usable connect / last disconnect for the account.
     * Actor-less → syncAndNotify falls back to the account owner.
     */
    private function notifyOnboarding(SocialAccount $socialAccount): void
    {
        $account = $socialAccount->workspace?->account;

        if (! $account?->isOnboardingOpen()) {
            return;
        }

        $hasOtherConnections = SocialAccount::query()
            ->whereIn('workspace_id', $account->workspaces()->select('id'))
            ->whereKeyNot($socialAccount->id)
            ->where('status', Status::Connected)
            ->exists();

        if (! $hasOtherConnections) {
            OnboardingStatusUpdated::dispatchForWorkspace($socialAccount->workspace_id);
        }
    }

    private function syncUsage(SocialAccount $socialAccount): void
    {
        if (PostHogService::isEnabled()) {
            SyncAccountUsage::dispatch(
                (string) $socialAccount->workspace->account_id,
                (string) $socialAccount->workspace_id,
            );
        }
    }
}
