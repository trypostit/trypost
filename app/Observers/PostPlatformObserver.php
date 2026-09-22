<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\PostPlatform\Status;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\PostPlatform;
use App\Services\PostHogService;

class PostPlatformObserver
{
    public function updated(PostPlatform $postPlatform): void
    {
        if (! PostHogService::isEnabled()
            || ! $postPlatform->wasChanged('status')
            || $postPlatform->status !== Status::Published) {
            return;
        }

        $accountId = $postPlatform
            ->loadMissing('post.workspace')
            ->post?->workspace?->account_id;

        if (! $accountId) {
            return;
        }

        SyncAccountUsage::dispatch((string) $accountId)->afterCommit();
    }
}
