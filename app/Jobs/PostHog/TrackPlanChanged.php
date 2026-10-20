<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\Billing\Interval;
use App\Enums\PostHog\PlanEvent;
use App\Models\Account;
use App\Models\Plan;
use App\Support\Billing\StripeValue;

class TrackPlanChanged extends AbstractTrackStripeEvent
{
    private const FROM_ITEM = 'data.previous_attributes.items.data.0';

    private const TO_ITEM = 'data.object.items.data.0';

    protected function shouldCapture(Account $account): bool
    {
        $fromPlan = $this->plan(self::FROM_ITEM);
        $toPlan = $this->plan(self::TO_ITEM);

        return filled($fromPlan) && filled($toPlan) && $fromPlan->isNot($toPlan);
    }

    protected function event(): string
    {
        return $this->monthlyMinorUnits(self::TO_ITEM) > $this->monthlyMinorUnits(self::FROM_ITEM)
            ? PlanEvent::Upgraded->value
            : PlanEvent::Downgraded->value;
    }

    /**
     * @return array{plan_from: string, plan_to: string, interval_from: string, interval_to: string, monthly_amount_from: float|null, monthly_amount_to: float|null}
     */
    protected function properties(Account $account): array
    {
        return [
            'plan_from' => $this->plan(self::FROM_ITEM)->slug->value,
            'plan_to' => $this->plan(self::TO_ITEM)->slug->value,
            'interval_from' => $this->interval(self::FROM_ITEM)->value,
            'interval_to' => $this->interval(self::TO_ITEM)->value,
            'monthly_amount_from' => $this->monthlyAmount(self::FROM_ITEM),
            'monthly_amount_to' => $this->monthlyAmount(self::TO_ITEM),
        ];
    }

    private function plan(string $itemPath): ?Plan
    {
        return once(fn (): ?Plan => transform(
            data_get($this->payload, "{$itemPath}.price.id"),
            fn (string $priceId): ?Plan => Plan::forStripePrices([$priceId])->first(),
        ));
    }

    private function interval(string $itemPath): Interval
    {
        return Interval::forPrice($this->plan($itemPath), data_get($this->payload, "{$itemPath}.price.id"));
    }

    /**
     * The item's price times its quantity, in the currency's minor units.
     */
    private function itemAmount(string $itemPath): ?int
    {
        $unitAmount = data_get($this->payload, "{$itemPath}.price.unit_amount");

        return is_int($unitAmount) ? $unitAmount * (int) data_get($this->payload, "{$itemPath}.quantity", 1) : null;
    }

    private function monthlyMinorUnits(string $itemPath): float
    {
        return $this->perMonth($itemPath, (float) ($this->itemAmount($itemPath) ?? 0));
    }

    private function perMonth(string $itemPath, float $amount): float
    {
        return $this->interval($itemPath) === Interval::Yearly ? $amount / 12 : $amount;
    }

    private function monthlyAmount(string $itemPath): ?float
    {
        return transform(
            StripeValue::amount($this->itemAmount($itemPath)),
            fn (float $amount): float => $this->perMonth($itemPath, $amount),
        );
    }
}
