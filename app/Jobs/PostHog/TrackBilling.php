<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\BillingEvent;
use App\Models\Account;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackBilling implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $accountId,
        public BillingEvent $event,
        public array $payload,
        public ?string $previousPlan = null,
    ) {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::shouldTrack()) {
            return;
        }

        $account = Account::with('plan')->find($this->accountId);

        if (! $account || ! $account->owner_id) {
            return;
        }

        $postHog->capture(
            (string) $account->owner_id,
            $this->event->value,
            [
                'stripe_status' => data_get($this->payload, 'data.object.status'),
                'plan_slug' => $account->plan?->slug->value,
                'previous_plan' => $this->previousPlan,
            ],
            $account,
        );

        SyncUser::dispatch((string) $account->owner_id);
    }
}
