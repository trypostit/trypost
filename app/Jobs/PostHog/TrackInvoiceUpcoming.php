<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\Billing\Interval;
use App\Enums\PostHog\InvoiceEvent;
use App\Models\Account;
use App\Models\Plan;
use App\Support\Billing\FirstFullCharge;
use App\Support\Billing\StripeAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class TrackInvoiceUpcoming extends AbstractTrackStripeEvent
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
     * @return array{amount: float|null, currency: string|null, charge_at: string|null, period_end: string|null, interval: string|null, is_first_full_charge: bool|null}
     */
    protected function properties(Account $account): array
    {
        $invoice = data_get($this->payload, 'data.object');
        $subscription = $account->subscription(Account::SUBSCRIPTION_NAME);
        $line = $this->subscriptionLine($invoice);
        $currency = data_get($invoice, 'currency');

        $chargeAt = $this->timestamp(data_get($invoice, 'next_payment_attempt') ?? data_get($line, 'period.start'));
        $interval = $this->interval(data_get($line, 'pricing.price_details.price') ?? $subscription?->stripe_price);

        return [
            'amount' => StripeAmount::toDecimal(data_get($invoice, 'amount_due'), $currency),
            'currency' => transform($currency, Str::upper(...)),
            'charge_at' => $chargeAt?->toIso8601String(),
            'period_end' => $this->timestamp(data_get($line, 'period.end'))?->toIso8601String(),
            'interval' => $interval?->value,
            'is_first_full_charge' => filled($subscription?->created_at) && filled($chargeAt) && filled($interval)
                ? FirstFullCharge::isFirst($subscription->created_at, $chargeAt, $interval)
                : null,
        ];
    }

    /**
     * The invoice's line for the subscription itself; proration lines also
     * belong to the subscription item, and a line without a parent is an
     * invoice item, so the flag must be exactly false.
     *
     * @return array<string, mixed>|null
     */
    private function subscriptionLine(mixed $invoice): ?array
    {
        return collect(data_get($invoice, 'lines.data', []))
            ->first(fn (array $line): bool => data_get($line, 'parent.subscription_item_details.proration') === false);
    }

    private function timestamp(mixed $at): ?CarbonImmutable
    {
        return transform($at, fn (mixed $at): CarbonImmutable => CarbonImmutable::createFromTimestampUTC((int) $at));
    }

    private function interval(?string $priceId): ?Interval
    {
        if (blank($priceId)) {
            return null;
        }

        return transform(
            Plan::forStripePrices([$priceId])->first(),
            fn (Plan $plan): Interval => Interval::forPrice($plan, $priceId),
        );
    }
}
