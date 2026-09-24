<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Dto\Analytics\TryPostPublicationIdentity;
use App\Enums\PostPlatform\Status;
use App\Jobs\Analytics\SyncTryPostPublication;
use App\Jobs\PostHog\SyncAccountPublishingActivity;
use App\Models\PostPlatform;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;
use App\Services\PostHogService;
use Throwable;

class PostPlatformObserver
{
    public function updated(PostPlatform $postPlatform): void
    {
        if (! $postPlatform->wasChanged('status')
            || $postPlatform->status !== Status::Published
            || ! filled($postPlatform->platform_post_id)) {
            return;
        }

        $this->dispatchAnalyticsSync($postPlatform);

        if (! PostHogService::isEnabled()) {
            return;
        }

        $accountId = $postPlatform
            ->loadMissing('post.workspace')
            ->post?->workspace?->account_id;

        if (! $accountId) {
            return;
        }

        SyncAccountPublishingActivity::dispatch((string) $accountId)
            ->delay(now()->addSeconds(SyncAccountPublishingActivity::DEBOUNCE_SECONDS))
            ->afterCommit();
    }

    private function dispatchAnalyticsSync(PostPlatform $postPlatform): void
    {
        try {
            $account = $postPlatform->loadMissing('socialAccount')->socialAccount;

            if (! $account || ! app(FollowerCollectorFactory::class)->supports($postPlatform->platform)) {
                return;
            }

            $identity = TryPostPublicationIdentity::fromAccount(
                $account,
                app(ResolveAnalyticsAccountKey::class)->for($account),
            );

            SyncTryPostPublication::dispatch($identity, $postPlatform->id)->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
