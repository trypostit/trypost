<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Events\OnboardingStatusUpdated;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\Account;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

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
        if (config('trypost.self_hosted')) {
            return;
        }

        $platform = $socialAccount->platform;

        if (! $platform instanceof Platform) {
            return;
        }

        $conflict = SocialAccount::query()
            ->where('workspace_id', $socialAccount->workspace_id)
            ->whereIn('platform', $platform->networkPlatformValues())
            ->exists();

        if ($conflict) {
            throw new NetworkAlreadyConnectedException($platform);
        }
    }

    public function created(SocialAccount $socialAccount): void
    {
        $this->syncUsage($socialAccount);

        $this->notifyOnboarding($socialAccount, function (Account $account) use ($socialAccount): bool {
            // Only the account's first connection unlocks the onboarding step.
            return $this->otherConnectedAccounts($account, $socialAccount)->doesntExist();
        });
    }

    public function deleted(SocialAccount $socialAccount): void
    {
        $this->syncUsage($socialAccount);

        $this->notifyOnboarding($socialAccount, function (Account $account) use ($socialAccount): bool {
            // The step only flips when the account's last connection disappears.
            return $this->otherConnectedAccounts($account, $socialAccount)->doesntExist();
        });
    }

    public function updated(SocialAccount $socialAccount): void
    {
        if (! $socialAccount->wasChanged('status')) {
            return;
        }

        $wasConnected = $socialAccount->getRawOriginal('status') === Status::Connected->value;
        $isConnected = $socialAccount->status === Status::Connected;

        if ($wasConnected === $isConnected) {
            return;
        }

        $account = $socialAccount->workspace?->account;

        if ($account?->isOnboardingOpen() && $this->otherConnectedAccounts($account, $socialAccount)->doesntExist()) {
            OnboardingStatusUpdated::dispatchForWorkspace(
                $socialAccount->workspace_id,
                $this->actorFor($socialAccount),
            );
        }
    }

    /**
     * @param  callable(Account): bool  $shouldNotify
     */
    private function notifyOnboarding(SocialAccount $socialAccount, callable $shouldNotify): void
    {
        $account = $socialAccount->workspace?->account;

        if (
            $account?->isOnboardingOpen()
            && $socialAccount->status === Status::Connected
            && $shouldNotify($account)
        ) {
            OnboardingStatusUpdated::dispatchForWorkspace(
                $socialAccount->workspace_id,
                $this->actorFor($socialAccount),
            );
        }
    }

    /**
     * @return Builder<SocialAccount>
     */
    private function otherConnectedAccounts(Account $account, SocialAccount $socialAccount): Builder
    {
        return SocialAccount::query()
            ->whereIn('workspace_id', $account->workspaces()->select('id'))
            ->whereKeyNot($socialAccount->id)
            ->where('status', Status::Connected);
    }

    private function actorFor(SocialAccount $socialAccount): ?User
    {
        $user = Auth::user();
        $accountId = $socialAccount->workspace?->account_id;

        return $user instanceof User && $accountId !== null && $user->belongsToAccount($accountId)
            ? $user
            : null;
    }

    private function syncUsage(SocialAccount $socialAccount): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        SyncAccountUsage::dispatch(
            (string) $socialAccount->workspace->account_id,
            (string) $socialAccount->workspace_id,
        );
    }
}
