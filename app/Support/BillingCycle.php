<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Models\AiUsageLog;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Subscription;

/**
 * Resolves the time window AI usage is measured against. The window follows
 * the Stripe billing cycle (monthly or yearly), anchored on the subscription
 * date. Usage is recorded for cost visibility, not metered as an entitlement.
 */
class BillingCycle
{
    /** @var array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    private ?array $window = null;

    private ?Subscription $subscription = null;

    private bool $subscriptionResolved = false;

    private function __construct(private readonly Account $account) {}

    public static function for(Account $account): self
    {
        return new self($account);
    }

    public function usedCredits(): int
    {
        return AiUsageLog::creditsUsedBetween(
            (string) $this->account->id,
            $this->periodStart(),
            $this->periodEnd(),
        );
    }

    public function periodStart(): CarbonImmutable
    {
        return $this->window()[0];
    }

    public function periodEnd(): CarbonImmutable
    {
        return $this->window()[1];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(): array
    {
        return $this->window ??= $this->computeWindow();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function computeWindow(): array
    {
        $subscription = $this->subscription();

        if ($subscription !== null && $subscription->onTrial()) {
            return [
                CarbonImmutable::parse($subscription->created_at),
                CarbonImmutable::parse($subscription->trial_ends_at),
            ];
        }

        $anchor = $this->anchor();
        $now = CarbonImmutable::now();
        $step = $this->isYearly() ? 12 : 1;

        $periods = 0;

        while ($anchor->addMonthsNoOverflow(($periods + 1) * $step)->lessThanOrEqualTo($now)) {
            $periods++;
        }

        return [
            $anchor->addMonthsNoOverflow($periods * $step),
            $anchor->addMonthsNoOverflow(($periods + 1) * $step),
        ];
    }

    private function isYearly(): bool
    {
        $subscription = $this->subscription();
        $plan = $this->account->plan;

        return $subscription !== null
            && $plan?->stripe_yearly_price_id !== null
            && $subscription->stripe_price === $plan->stripe_yearly_price_id;
    }

    private function anchor(): CarbonImmutable
    {
        $subscription = $this->subscription();

        $anchor = $subscription?->trial_ends_at
            ?? $subscription?->created_at
            ?? $this->account->created_at
            ?? CarbonImmutable::now();

        return CarbonImmutable::parse($anchor);
    }

    private function subscription(): ?Subscription
    {
        if (! $this->subscriptionResolved) {
            $this->subscription = $this->account->subscription(Account::SUBSCRIPTION_NAME);
            $this->subscriptionResolved = true;
        }

        return $this->subscription;
    }
}
