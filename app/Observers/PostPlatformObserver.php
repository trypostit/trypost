<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\PostPlatform\Status;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\PostPlatform;
use App\Services\PostHogService;
use Illuminate\Support\Facades\DB;

class PostPlatformObserver
{
    public function updated(PostPlatform $postPlatform): void
    {
        if (! PostHogService::isEnabled()
            || ! $postPlatform->wasChanged('status')
            || $postPlatform->status !== Status::Published) {
            return;
        }

        $postPlatform->loadMissing('post.workspace');

        $workspace = $postPlatform->post?->workspace;

        if (! $workspace) {
            return;
        }

        DB::afterCommit(fn () => SyncAccountUsage::dispatch((string) $workspace->account_id));
    }
}
