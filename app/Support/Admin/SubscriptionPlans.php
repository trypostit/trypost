<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Enums\Billing\Interval;
use App\Models\Plan;

/**
 * How the backoffice reads a subscription's plan and interval: from its
 * Stripe price, where a price no plan lists anymore is legacy. Where a
 * subscription stands is the SubscriptionStatusFilter it falls in.
 */
final class SubscriptionPlans
{
    public const string LEGACY = 'legacy';

    /** How many months one of each Stripe billing interval lasts. */
    public const array MONTHS_PER_INTERVAL = ['day' => 12 / 365, 'week' => 12 / 52, 'month' => 1, 'year' => 12];

    /**
     * Every plan's prices, by price id, read once per request.
     *
     * @return array<string, array{plan: string, name: string, interval: string}>
     */
    public static function byPrice(): array
    {
        return once(fn (): array => Plan::query()
            ->get()
            ->flatMap(fn (Plan $plan): array => collect(Interval::cases())
                ->mapWithKeys(fn (Interval $interval): array => [
                    (string) $interval->priceIdFor($plan) => ['plan' => $plan->slug->value, 'name' => $plan->name, 'interval' => $interval->value],
                ])
                ->all())
            ->reject(fn (array $price, string $id): bool => blank($id))
            ->all());
    }

    /**
     * @return array{plan: string, name: string, interval: string}
     */
    public static function describe(?string $price): array
    {
        $known = data_get(self::byPrice(), (string) $price);

        if (filled($known)) {
            return $known;
        }

        return ['plan' => self::LEGACY, 'name' => 'Legacy', 'interval' => 'unknown'];
    }
}
