<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\PlanEvent;
use App\Models\Account;
use App\Models\Plan;

class TrackPlanChanged extends AbstractTrackStripeSubscriptionEvent
{
    protected function requiresPlan(): bool
    {
        return false;
    }

    protected function shouldCapture(Account $account): bool
    {
        $from = $this->planFor('data.previous_attributes.items.data.0.price.id');
        $to = $this->planFor('data.object.items.data.0.price.id');

        return $from !== null && $to !== null && $from->isNot($to);
    }

    protected function event(): string
    {
        return $this->monthlyAmount('data.object.items.data.0.price') > $this->monthlyAmount('data.previous_attributes.items.data.0.price')
            ? PlanEvent::Upgraded->value
            : PlanEvent::Downgraded->value;
    }

    /**
     * @return array{plan_from: string|null, plan_to: string|null, interval_from: string|null, interval_to: string|null, monthly_amount_from: float, monthly_amount_to: float}
     */
    protected function properties(Account $account): array
    {
        $fromPath = 'data.previous_attributes.items.data.0.price';
        $toPath = 'data.object.items.data.0.price';

        return [
            'plan_from' => $this->planFor("{$fromPath}.id")?->slug->value,
            'plan_to' => $this->planFor("{$toPath}.id")?->slug->value,
            'interval_from' => data_get($this->payload, "{$fromPath}.recurring.interval"),
            'interval_to' => data_get($this->payload, "{$toPath}.recurring.interval"),
            'monthly_amount_from' => $this->monthlyAmount($fromPath) / 100,
            'monthly_amount_to' => $this->monthlyAmount($toPath) / 100,
        ];
    }

    private function planFor(string $priceIdPath): ?Plan
    {
        $priceId = data_get($this->payload, $priceIdPath);

        return filled($priceId) ? Plan::forStripePrices([$priceId])->first() : null;
    }

    private function monthlyAmount(string $pricePath): float
    {
        $amount = (float) data_get($this->payload, "{$pricePath}.unit_amount", 0);

        return data_get($this->payload, "{$pricePath}.recurring.interval") === 'year' ? $amount / 12 : $amount;
    }
}
