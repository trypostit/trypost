<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SocialAccount\Status;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\PostHog\IdentifyConnectedPlatforms;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;
use App\Services\PostHogService;
use App\Services\Repurpose\RepurposeAccountSync;
use Carbon\CarbonImmutable;
use Throwable;

class SocialAccountObserver
{
    public function created(SocialAccount $socialAccount): void
    {
        $this->syncUsageAndIdentify($socialAccount);
        $this->dispatchInitialAnalytics($socialAccount);
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

        if (! $socialAccount->wasChanged('status')) {
            return;
        }

        $wasConnected = $socialAccount->getRawOriginal('status') === Status::Connected->value;
        $isConnected = $socialAccount->status === Status::Connected;

        if ($wasConnected !== $isConnected) {
            $this->identifyConnectedPlatforms($socialAccount);

            if ($isConnected) {
                $this->dispatchInitialAnalytics($socialAccount);
            }
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

    private function dispatchInitialAnalytics(SocialAccount $socialAccount): void
    {
        try {
            $currentAccount = SocialAccount::query()
                ->connected()
                ->active()
                ->find($socialAccount->id);

            if (! $currentAccount
                || ! app(FollowerCollectorFactory::class)->supports($currentAccount->platform)) {
                return;
            }

            CollectAccountDailySnapshot::dispatch(
                $currentAccount->id,
                CarbonImmutable::now('UTC')->toDateString(),
            )->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
