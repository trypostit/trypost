<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Concerns;

use App\Enums\PostHog\WorkspaceEvent;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Models\User;
use App\Services\PostHogService;

trait TracksWorkspaceLimitHit
{
    private function trackLimitHit(User $user): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        rescue(function () use ($user): void {
            $account = $user->account->loadMissing('plan');

            CaptureAccountEvent::dispatch(
                (string) $user->id,
                (string) $account->id,
                WorkspaceEvent::LimitHit,
                [
                    'plan_slug' => $account->plan?->slug?->value,
                    'workspaces_count' => $account->workspaces()->count(),
                    'workspace_limit' => $account->workspaceLimit(),
                ],
            );
        });
    }
}
