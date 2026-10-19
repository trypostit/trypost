<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\InvoiceEvent;
use App\Models\Account;
use Carbon\CarbonImmutable;

class TrackInvoicePaymentFailed extends AbstractTrackStripeSubscriptionEvent
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
     * @return array{attempt_count: int, amount: float, currency: string|null, next_attempt_at: string|null, is_final_attempt: bool, billing_reason: string|null, invoice_id: string|null}
     */
    protected function properties(Account $account): array
    {
        $nextAttempt = data_get($this->payload, 'data.object.next_payment_attempt');
        $currency = data_get($this->payload, 'data.object.currency');

        return [
            'attempt_count' => (int) data_get($this->payload, 'data.object.attempt_count', 0),
            'amount' => (float) data_get($this->payload, 'data.object.amount_due', 0) / 100,
            'currency' => filled($currency) ? strtoupper($currency) : null,
            'next_attempt_at' => filled($nextAttempt) ? CarbonImmutable::createFromTimestampUTC((int) $nextAttempt)->toIso8601String() : null,
            'is_final_attempt' => blank($nextAttempt),
            'billing_reason' => data_get($this->payload, 'data.object.billing_reason'),
            'invoice_id' => data_get($this->payload, 'data.object.id'),
        ];
    }
}
