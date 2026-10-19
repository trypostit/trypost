<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Analytics\DispatchAccountAnalytics;
use App\Actions\SocialAccount\ListPinterestBoards;
use App\Enums\PostHog\SocialAccountEvent;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Jobs\PostHog\IdentifyConnectedPlatforms;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\SocialAccount;
use App\Services\PostHogService;
use App\Services\Repurpose\RepurposeAccountSync;
use App\Support\PostHog\SocialAccountSnapshot;

class SocialAccountObserver
{
    public function creating(SocialAccount $socialAccount): void
    {
        if ($socialAccount->position !== null) {
            return;
        }

        $last = SocialAccount::withoutGlobalScopes()
            ->where('workspace_id', $socialAccount->workspace_id)
            ->max('position');

        $socialAccount->position = $last === null ? 0 : ((int) $last) + 1;
    }

    public function created(SocialAccount $socialAccount): void
    {
        $this->syncUsageAndIdentify($socialAccount);
        app(DispatchAccountAnalytics::class)->handle($socialAccount);
    }

    public function deleted(SocialAccount $socialAccount): void
    {
        $this->syncUsageAndIdentify($socialAccount);
        $this->forgetPinterestBoards($socialAccount);
    }

    public function deleting(SocialAccount $socialAccount): void
    {
        app(RepurposeAccountSync::class)->accountRemoved($socialAccount);
    }

    public function updated(SocialAccount $socialAccount): void
    {
        app(RepurposeAccountSync::class)->accountChanged($socialAccount);

        if ($socialAccount->wasChanged('access_token')) {
            $this->forgetPinterestBoards($socialAccount);
        }

        $wasConnected = $socialAccount->getRawOriginal('status') === Status::Connected->value;
        $isConnected = $socialAccount->status === Status::Connected;
        $connectionChanged = $socialAccount->wasChanged('status') && $wasConnected !== $isConnected;

        if ($connectionChanged) {
            $this->identifyConnectedPlatforms($socialAccount);
        }

        if ($connectionChanged && $isConnected) {
            app(DispatchAccountAnalytics::class)->handle($socialAccount);
        }

        if ($connectionChanged && $wasConnected) {
            $this->trackDisconnected($socialAccount);
        }
    }

    private function trackDisconnected(SocialAccount $socialAccount): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        rescue(function () use ($socialAccount): void {
            $account = $socialAccount->loadMissing('workspace.account')->workspace?->account;

            if (blank($account?->owner_id)) {
                return;
            }

            CaptureAccountEvent::dispatch(
                (string) $account->owner_id,
                (string) $account->id,
                SocialAccountEvent::Disconnected,
                SocialAccountSnapshot::of($socialAccount, $socialAccount->status->value, withErrorType: true),
            )->afterCommit();
        });
    }

    private function forgetPinterestBoards(SocialAccount $socialAccount): void
    {
        if ($socialAccount->platform === Platform::Pinterest) {
            ListPinterestBoards::forget($socialAccount->id);
        }
    }

    private function syncUsageAndIdentify(SocialAccount $socialAccount): void
    {
        $this->syncUsage($socialAccount);
        $this->identifyConnectedPlatforms($socialAccount);
    }

    private function identifyConnectedPlatforms(SocialAccount $socialAccount): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        IdentifyConnectedPlatforms::dispatch((string) $socialAccount->workspace_id);
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
