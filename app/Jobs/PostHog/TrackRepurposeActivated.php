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

    public function __construct(public string $repurposeId)
    {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $repurpose = Repurpose::query()->with(['sourceAccount', 'workspace.account.plan'])->find($this->repurposeId);
        $account = $repurpose?->workspace?->account;

        if (blank($account) || blank($repurpose->user_id)) {
            return;
        }

        $postHog->capture(
            (string) $repurpose->user_id,
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
