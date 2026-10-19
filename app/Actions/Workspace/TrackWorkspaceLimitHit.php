<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\PostHog\WorkspaceEvent;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Models\User;
use App\Services\PostHogService;

class TrackWorkspaceLimitHit
{
    public static function execute(User $user): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        rescue(function () use ($user): void {
            $account = $user->account;

            if (! $account?->hasActiveSubscription() || $account->canCreateWorkspace()) {
                return;
            }

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
