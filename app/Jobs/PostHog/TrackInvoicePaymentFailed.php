<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\InvoiceEvent;
use App\Models\Account;
use App\Support\Billing\StripeAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class TrackInvoicePaymentFailed extends AbstractTrackStripeEvent
{
    protected function requiresPlan(): bool
    {
        return false;
    }

    protected function event(): string
    {
        return InvoiceEvent::PaymentFailed->value;
    }

    /**
     * @return array{attempt_count: int, amount: float|null, currency: string|null, next_attempt_at: string|null, is_final_attempt: bool, billing_reason: string|null, invoice_id: string|null}
     */
    protected function properties(Account $account): array
    {
        $nextAttempt = data_get($this->payload, 'data.object.next_payment_attempt');
        $currency = data_get($this->payload, 'data.object.currency');

        return [
            'attempt_count' => (int) data_get($this->payload, 'data.object.attempt_count', 0),
            'amount' => StripeAmount::toDecimal(data_get($this->payload, 'data.object.amount_due'), $currency),
            'currency' => transform($currency, Str::upper(...)),
            'next_attempt_at' => transform($nextAttempt, fn (mixed $at): string => CarbonImmutable::createFromTimestampUTC((int) $at)->toIso8601String()),
            'is_final_attempt' => blank($nextAttempt),
            'billing_reason' => data_get($this->payload, 'data.object.billing_reason'),
            'invoice_id' => data_get($this->payload, 'data.object.id'),
        ];
    }
}
