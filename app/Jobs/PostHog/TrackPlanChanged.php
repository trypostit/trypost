<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\Billing\Interval;
use App\Enums\PostHog\PlanEvent;
use App\Models\Account;
use App\Models\Plan;
use App\Support\Billing\StripeAmount;

class TrackPlanChanged extends AbstractTrackStripeEvent
{
    private const FROM_ITEM = 'data.previous_attributes.items.data.0';

    private const TO_ITEM = 'data.object.items.data.0';

    /**
     * @var array<string, Plan|null>
     */
    private array $plans = [];

    protected function requiresPlan(): bool
    {
        return false;
    }

    protected function shouldCapture(Account $account): bool
    {
        $fromPlan = $this->plan(self::FROM_ITEM);
        $toPlan = $this->plan(self::TO_ITEM);

        return filled($fromPlan) && filled($toPlan) && $fromPlan->isNot($toPlan);
    }

    protected function event(): string
    {
        return $this->monthlyAmount(self::TO_ITEM) > $this->monthlyAmount(self::FROM_ITEM)
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
        if (! array_key_exists($itemPath, $this->plans)) {
            $priceId = data_get($this->payload, "{$itemPath}.price.id");

            $this->plans[$itemPath] = filled($priceId) ? Plan::forStripePrices([$priceId])->first() : null;
        }

        return $this->plans[$itemPath];
    }

    private function interval(string $itemPath): Interval
    {
        return Interval::forPrice($this->plan($itemPath), data_get($this->payload, "{$itemPath}.price.id"));
    }

    private function monthlyAmount(string $itemPath): ?float
    {
        $unitAmount = data_get($this->payload, "{$itemPath}.price.unit_amount");
        $quantity = (int) data_get($this->payload, "{$itemPath}.quantity", 1);

        $amount = StripeAmount::toDecimal(
            is_int($unitAmount) ? $unitAmount * $quantity : null,
            data_get($this->payload, "{$itemPath}.price.currency"),
        );

        if (blank($amount)) {
            return null;
        }

        return $this->interval($itemPath) === Interval::Yearly ? $amount / 12 : $amount;
    }
}
