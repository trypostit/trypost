<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Analytics\DispatchAccountAnalytics;
use App\Enums\SocialAccount\Status;
use App\Jobs\PostHog\IdentifyConnectedPlatforms;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\SocialAccount;
use App\Services\PostHogService;
use App\Services\Repurpose\RepurposeAccountSync;

class SocialAccountObserver
{
    public function created(SocialAccount $socialAccount): void
    {
        $this->syncUsageAndIdentify($socialAccount);
        app(DispatchAccountAnalytics::class)->handle($socialAccount);
    }

    public function deleted(SocialAccount $socialAccount): void
    {
        $this->syncUsageAndIdentify($socialAccount);
    }

    public function deleting(SocialAccount $socialAccount): void
    {
        app(RepurposeAccountSync::class)->accountRemoved($socialAccount);
    }

    public function updated(SocialAccount $socialAccount): void
    {
        app(RepurposeAccountSync::class)->accountChanged($socialAccount);

        $wasConnected = $socialAccount->getRawOriginal('status') === Status::Connected->value;
        $isConnected = $socialAccount->status === Status::Connected;
        $connectionChanged = $socialAccount->wasChanged('status') && $wasConnected !== $isConnected;
        $becameActive = $socialAccount->wasChanged('is_active') && $socialAccount->is_active;

        if ($connectionChanged) {
            $this->identifyConnectedPlatforms($socialAccount);
        }

        if (($connectionChanged && $isConnected) || $becameActive) {
            app(DispatchAccountAnalytics::class)->handle($socialAccount);
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
