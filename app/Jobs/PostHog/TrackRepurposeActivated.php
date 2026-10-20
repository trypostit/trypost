<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\RepurposeEvent;
use App\Models\Repurpose;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackRepurposeActivated implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  string|null  $userId  Who activated it; without one the event goes to its creator, then to the account owner.
     */
    public function __construct(
        public string $repurposeId,
        public ?string $userId = null,
    ) {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $repurpose = Repurpose::query()->with(['sourceAccount', 'workspace.account.plan'])->find($this->repurposeId);
        $account = $repurpose?->workspace?->account;

        $distinctId = $this->userId ?? $repurpose?->user_id ?? $account?->owner_id;

        if (blank($account) || blank($distinctId)) {
            return;
        }

        $postHog->capture(
            (string) $distinctId,
            RepurposeEvent::Activated->value,
            [
                'repurpose_id' => (string) $repurpose->id,
                'source' => $repurpose->sourceAccount?->platform->value,
                'destinations_count' => count($repurpose->destinations),
            ],
            $account,
        );
    }
}
