<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\Billing\Interval;
use App\Enums\PostHog\InvoiceEvent;
use App\Models\Account;
use App\Models\Plan;
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
        $subscription = $account->subscription(Account::SUBSCRIPTION_NAME);
        $line = $this->subscriptionLine();
        $currency = data_get($this->payload, 'data.object.currency');

        $chargeAt = $this->timestamp(data_get($this->payload, 'data.object.next_payment_attempt') ?? data_get($line, 'period.start'));
        $periodEnd = $this->timestamp(data_get($line, 'period.end'));
        $interval = $this->interval(data_get($line, 'pricing.price_details.price') ?? $subscription?->stripe_price);

        return [
            'amount' => (float) data_get($this->payload, 'data.object.amount_due', 0) / 100,
            'currency' => filled($currency) ? strtoupper($currency) : null,
            'charge_at' => $chargeAt?->toIso8601String(),
            'period_end' => $periodEnd?->toIso8601String(),
            'interval' => $interval?->value,
            'is_first_full_charge' => filled($subscription?->created_at) && $chargeAt && $interval
                ? FirstFullCharge::isFirst($subscription->created_at, $chargeAt, $interval)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function subscriptionLine(): ?array
    {
        return collect(data_get($this->payload, 'data.object.lines.data', []))
            ->first(fn (mixed $line): bool => data_get($line, 'parent.subscription_item_details.proration') === false);
    }

    private function interval(?string $priceId): ?Interval
    {
        if (blank($priceId)) {
            return null;
        }

        $plan = Plan::forStripePrices([$priceId])->first();

        return $plan ? Interval::forPrice($plan, $priceId) : null;
    }

    private function timestamp(mixed $timestamp): ?CarbonImmutable
    {
        return filled($timestamp) ? CarbonImmutable::createFromTimestampUTC((int) $timestamp) : null;
    }
}
