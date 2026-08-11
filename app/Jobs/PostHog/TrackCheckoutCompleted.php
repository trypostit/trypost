<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\CheckoutEvent;
use App\Models\Account;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackCheckoutCompleted implements ShouldQueue
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

        $unitAmount = data_get($this->payload, 'data.object.items.data.0.price.unit_amount');
        $currency = data_get($this->payload, 'data.object.items.data.0.price.currency');
        $priceId = data_get($this->payload, 'data.object.items.data.0.price.id');

        $properties = [
            'plan_name' => $account->plan->name,
            'interval' => $priceId === $account->plan->stripe_yearly_price_id ? 'yearly' : 'monthly',
            'persona' => $account->owner?->persona?->value,
        ];

        if (is_int($unitAmount) && is_string($currency)) {
            $properties['conversion_value'] = (float) ($unitAmount / 100);
            $properties['conversion_currency'] = strtoupper($currency);
            $properties['conversion_transaction_id'] = data_get($this->payload, 'data.object.id');
        }

        $postHog->capture(
            (string) $account->owner_id,
            CheckoutEvent::Completed->value,
            $properties,
            $account,
        );
    }
}
