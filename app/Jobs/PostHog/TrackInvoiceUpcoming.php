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
        $subscription = $account->subscription(Account::SUBSCRIPTION_NAME);
        $line = $this->subscriptionLine();
        $currency = data_get($this->payload, 'data.object.currency');

        $chargeAt = transform(
            data_get($this->payload, 'data.object.next_payment_attempt') ?? data_get($line, 'period.start'),
            fn (mixed $at): CarbonImmutable => CarbonImmutable::createFromTimestampUTC((int) $at),
        );
        $periodEnd = transform(data_get($line, 'period.end'), fn (mixed $at): string => CarbonImmutable::createFromTimestampUTC((int) $at)->toIso8601String());
        $interval = $this->interval(data_get($line, 'pricing.price_details.price') ?? $subscription?->stripe_price);

        return [
            'amount' => StripeAmount::toDecimal(data_get($this->payload, 'data.object.amount_due'), $currency),
            'currency' => transform($currency, Str::upper(...)),
            'charge_at' => $chargeAt?->toIso8601String(),
            'period_end' => $periodEnd,
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
            ->firstWhere('parent.subscription_item_details.proration', '===', false);
    }

    private function interval(?string $priceId): ?Interval
    {
        if (blank($priceId)) {
            return null;
        }

        $plan = Plan::forStripePrices([$priceId])->first();

        return $plan ? Interval::forPrice($plan, $priceId) : null;
    }
}
