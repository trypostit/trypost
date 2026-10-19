<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\Account\LifecycleStage;
use App\Enums\PostHog\ActivationEvent;
use App\Enums\PostHog\LifecycleEvent;
use App\Models\Account;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackLifecycleChange implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $accountId,
        public ?LifecycleStage $previousStage,
        public LifecycleStage $stage,
        public bool $activatedNow,
    ) {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $account = Account::with('plan')->find($this->accountId);

        if (blank($account)) {
            return;
        }

        $postHog->groupIdentify('account', (string) $account->id, [
            'lifecycle_stage' => $account->lifecycle_stage?->value,
            'lifecycle_stage_changed_at' => $account->lifecycle_stage_changed_at?->toIso8601String(),
            'activated_at' => $account->activated_at?->toIso8601String(),
        ]);

        if (blank($account->owner_id)) {
            return;
        }

        $ownerId = (string) $account->owner_id;

        $postHog->identify($ownerId, ['lifecycle_stage' => $account->lifecycle_stage?->value]);

        if (blank($this->previousStage)) {
            return;
        }

        if ($this->previousStage !== $this->stage) {
            $postHog->capture($ownerId, LifecycleEvent::StageChanged->value, [
                'stage' => $this->stage->value,
                'previous_stage' => $this->previousStage->value,
                'reached_at' => $account->lifecycle_stage_changed_at?->toIso8601String(),
            ], $account);
        }

        if ($this->activatedNow && filled($account->activated_at)) {
            $postHog->capture($ownerId, ActivationEvent::Reached->value, [
                'reached_at' => $account->activated_at->toIso8601String(),
                'days_since_signup' => (int) $account->created_at->diffInDays($account->activated_at),
                'channels_count' => $account->socialAccounts()->connected()->count(),
            ], $account);
        }
    }
}
