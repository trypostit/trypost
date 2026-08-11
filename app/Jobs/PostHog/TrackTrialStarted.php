<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\TrialEvent;
use App\Models\Account;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class TrackTrialStarted implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $accountId,
        public array $payload,
    ) {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $account = Account::with(['plan', 'owner'])->find($this->accountId);

        if (! $account || ! $account->owner_id || ! $account->plan) {
            return;
        }

        $priceId = data_get($this->payload, 'data.object.items.data.0.price.id');
        $trialEnd = data_get($this->payload, 'data.object.trial_end');

        $properties = [
            'plan_name' => $account->plan->name,
            'interval' => $priceId === $account->plan->stripe_yearly_price_id ? 'yearly' : 'monthly',
            'persona' => $account->owner?->persona?->value,
        ];

        if (is_int($trialEnd)) {
            $properties['trial_ends_at'] = Carbon::createFromTimestamp($trialEnd)->toIso8601String();
        }

        $postHog->capture(
            (string) $account->owner_id,
            TrialEvent::Started->value,
            $properties,
            $account,
        );
    }
}
