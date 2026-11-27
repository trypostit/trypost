<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonInterface;

/**
 * How the backoffice reads a subscription: its plan and interval from the
 * Stripe price (a price no plan lists anymore is legacy), and the state it
 * is in now (a scheduled cancellation and an ended subscription read apart
 * from Stripe's own status).
 */
final class SubscriptionPlans
{
    public const string LEGACY = 'legacy';

    /** Stripe statuses of a subscription that is paid for. */
    public const array PAYING_STATUSES = ['active', 'past_due'];

    /** Stripe statuses of a checkout that never billed. */
    public const array NEVER_BILLED_STATUSES = ['incomplete', 'incomplete_expired'];

    /** How many months one of each Stripe billing interval lasts. */
    public const array MONTHS_PER_INTERVAL = ['day' => 12 / 365, 'week' => 12 / 52, 'month' => 1, 'year' => 12];

    /**
     * Every plan's prices, by price id.
     *
     * @return array<string, array{plan: string, name: string, interval: string}>
     */
    public static function byPrice(): array
    {
        return Plan::query()
            ->get()
            ->flatMap(fn (Plan $plan): array => [
                (string) $plan->stripe_monthly_price_id => ['plan' => $plan->slug->value, 'name' => $plan->name, 'interval' => 'monthly'],
                (string) $plan->stripe_yearly_price_id => ['plan' => $plan->slug->value, 'name' => $plan->name, 'interval' => 'yearly'],
            ])
            ->reject(fn (array $price, string $id): bool => blank($id))
            ->all();
    }

    /**
     * @param  array<string, array{plan: string, name: string, interval: string}>  $prices
     * @return array{plan: string, name: string, interval: string}
     */
    public static function describe(?string $price, array $prices): array
    {
        $known = data_get($prices, (string) $price);

        if (filled($known)) {
            return $known;
        }

        return ['plan' => self::LEGACY, 'name' => 'Legacy', 'interval' => 'unknown'];
    }

    /** Ended, cancelling at the period end, or Stripe's status. */
    public static function state(Subscription $subscription, CarbonInterface $now): string
    {
        if ($subscription->ends_at !== null && $subscription->ends_at->lte($now)) {
            return 'canceled';
        }

        if ($subscription->ends_at !== null) {
            return 'cancelling';
        }

        return $subscription->stripe_status;
    }
}
