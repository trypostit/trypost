<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\InvoiceEvent;
use App\Models\Account;
use App\Support\Billing\FirstFullCharge;
use Carbon\CarbonImmutable;

class TrackInvoiceUpcoming extends AbstractTrackStripeSubscriptionEvent
{
    protected function requiresPlan(): bool
    {
        return false;
    }

    protected function event(): string
    {
        return InvoiceEvent::Upcoming->value;
    }

    /**
     * @return array{amount: float, currency: string|null, charge_at: string|null, period_end: string|null, interval: string|null, is_first_full_charge: bool|null}
     */
    protected function properties(Account $account): array
    {
        $chargeTimestamp = data_get($this->payload, 'data.object.next_payment_attempt');
        $periodEndTimestamp = data_get($this->payload, 'data.object.lines.data.0.period.end');
        $interval = data_get($this->payload, 'data.object.lines.data.0.price.recurring.interval');
        $currency = data_get($this->payload, 'data.object.currency');
        $startedAt = $account->subscription(Account::SUBSCRIPTION_NAME)?->created_at;

        $chargeAt = filled($chargeTimestamp) ? CarbonImmutable::createFromTimestampUTC((int) $chargeTimestamp) : null;
        $periodEnd = filled($periodEndTimestamp) ? CarbonImmutable::createFromTimestampUTC((int) $periodEndTimestamp) : null;

        return [
            'amount' => (float) data_get($this->payload, 'data.object.amount_due', 0) / 100,
            'currency' => filled($currency) ? strtoupper($currency) : null,
            'charge_at' => $chargeAt?->toIso8601String(),
            'period_end' => $periodEnd?->toIso8601String(),
            'interval' => $interval,
            'is_first_full_charge' => filled($startedAt) && $chargeAt && filled($interval)
                ? FirstFullCharge::isFirst($startedAt, $chargeAt, $interval)
                : null,
        ];
    }
}
