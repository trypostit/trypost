<?php

declare(strict_types=1);

namespace App\Jobs\Gtm;

use App\Models\Account;
use App\Services\GtmServerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackPurchase implements ShouldQueue
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
        $this->onQueue('gtm');
    }

    public function handle(GtmServerService $gtm): void
    {
        if (! GtmServerService::isEnabled()) {
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
            'plan_interval' => $priceId === $account->plan->stripe_yearly_price_id ? 'yearly' : 'monthly',
            'persona' => $account->owner?->persona?->value,
        ];

        if (is_int($unitAmount) && is_string($currency)) {
            $properties['conversion_value'] = (float) ($unitAmount / 100);
            $properties['conversion_currency'] = strtoupper($currency);
            $properties['conversion_transaction_id'] = data_get($this->payload, 'data.object.id');
        }

        $gtm->capture('purchase', $properties, (string) $account->owner_id);
    }
}
