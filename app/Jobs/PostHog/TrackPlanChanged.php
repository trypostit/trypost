<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\Billing\Interval;
use App\Enums\PostHog\PlanEvent;
use App\Models\Account;
use App\Models\Plan;

class TrackPlanChanged extends AbstractTrackStripeSubscriptionEvent
{
    private const FROM_ITEM = 'data.previous_attributes.items.data.0';

    private const TO_ITEM = 'data.object.items.data.0';

    private ?Plan $fromPlan = null;

    private ?Plan $toPlan = null;

    protected function requiresPlan(): bool
    {
        return false;
    }

    protected function shouldCapture(Account $account): bool
    {
        $this->fromPlan = $this->planFor(self::FROM_ITEM);
        $this->toPlan = $this->planFor(self::TO_ITEM);

        return $this->fromPlan !== null && $this->toPlan !== null && $this->fromPlan->isNot($this->toPlan);
    }

    protected function event(): string
    {
        return $this->monthlyAmount(self::TO_ITEM, $this->toPlan) > $this->monthlyAmount(self::FROM_ITEM, $this->fromPlan)
            ? PlanEvent::Upgraded->value
            : PlanEvent::Downgraded->value;
    }

    /**
     * @return array{plan_from: string, plan_to: string, interval_from: string, interval_to: string, monthly_amount_from: float, monthly_amount_to: float}
     */
    protected function properties(Account $account): array
    {
        return [
            'plan_from' => $this->fromPlan->slug->value,
            'plan_to' => $this->toPlan->slug->value,
            'interval_from' => $this->interval(self::FROM_ITEM, $this->fromPlan)->value,
            'interval_to' => $this->interval(self::TO_ITEM, $this->toPlan)->value,
            'monthly_amount_from' => $this->monthlyAmount(self::FROM_ITEM, $this->fromPlan) / 100,
            'monthly_amount_to' => $this->monthlyAmount(self::TO_ITEM, $this->toPlan) / 100,
        ];
    }

    private function planFor(string $itemPath): ?Plan
    {
        $priceId = data_get($this->payload, "{$itemPath}.price.id");

        return filled($priceId) ? Plan::forStripePrices([$priceId])->first() : null;
    }

    private function interval(string $itemPath, Plan $plan): Interval
    {
        return Interval::forPrice($plan, data_get($this->payload, "{$itemPath}.price.id"));
    }

    private function monthlyAmount(string $itemPath, Plan $plan): float
    {
        $amount = (float) data_get($this->payload, "{$itemPath}.price.unit_amount", 0)
            * (int) data_get($this->payload, "{$itemPath}.quantity", 1);

        return $this->interval($itemPath, $plan) === Interval::Yearly ? $amount / 12 : $amount;
    }
}
