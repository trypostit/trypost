<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SocialAccount\Status;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
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

        $wasConnected = $socialAccount->getRawOriginal('status') === Status::Connected->value;
        $isConnected = $socialAccount->status === Status::Connected;
        $connectionChanged = $socialAccount->wasChanged('status') && $wasConnected !== $isConnected;
        $becameActive = $socialAccount->wasChanged('is_active') && $socialAccount->is_active;

        if ($connectionChanged) {
            $this->identifyConnectedPlatforms($socialAccount);
        }

        if (($connectionChanged && $isConnected) || $becameActive) {
            $this->dispatchInitialAnalytics($socialAccount);
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
                ->includedInAnalytics()
                ->find($socialAccount->id);

            if (! $currentAccount) {
                return;
            }

            if (app(FollowerCollectorFactory::class)->supports($currentAccount->platform)) {
                CollectAccountDailySnapshot::dispatch(
                    $currentAccount->id,
                    CarbonImmutable::now('UTC')->toDateString(),
                )->afterCommit();
            }

            BootstrapAccountAnalytics::dispatch($currentAccount->id)->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
