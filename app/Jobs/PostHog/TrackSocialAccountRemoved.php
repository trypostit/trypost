<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\SocialAccountEvent;
use App\Enums\SocialAccount\Platform;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A member removed the channel. The row is gone by the time this runs, so the
 * channel is described by the values read before it was deleted.
 */
class TrackSocialAccountRemoved implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const REASON = 'removed';

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $userId,
        public string $socialAccountId,
        public string $workspaceId,
        public Platform $platform,
    ) {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $account = Workspace::query()->with('account.plan')->find($this->workspaceId)?->account;

        if (blank($account)) {
            return;
        }

        $postHog->capture(
            $this->userId,
            SocialAccountEvent::Disconnected->value,
            [
                'social_account_id' => $this->socialAccountId,
                'platform' => $this->platform->value,
                'network' => $this->platform->network(),
                'workspace_id' => $this->workspaceId,
                'reason' => self::REASON,
            ],
            $account,
        );
    }
}
