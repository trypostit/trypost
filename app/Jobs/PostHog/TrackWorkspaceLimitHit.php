<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\WorkspaceEvent;
use App\Models\User;
use App\Services\PostHogService;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackWorkspaceLimitHit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * The plan and the limit are the ones the refusal saw: an upgrade right
     * after it must not make the event read as a hit on the new plan.
     */
    public function __construct(
        public string $userId,
        public ?string $planSlug,
        public ?int $workspaceLimit,
        public CarbonInterface $hitAt,
    ) {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $account = User::query()->with('account.plan')->find($this->userId)?->account;

        if (blank($account)) {
            return;
        }

        $postHog->capture(
            $this->userId,
            WorkspaceEvent::LimitHit->value,
            [
                'plan_slug' => $this->planSlug,
                'workspaces_count' => $account->workspaces()->where('workspaces.created_at', '<=', $this->hitAt)->count(),
                'workspace_limit' => $this->workspaceLimit,
            ],
            $account,
        );
    }
}
