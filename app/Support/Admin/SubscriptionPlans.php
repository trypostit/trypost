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
    /** The plan key of a price no plan lists anymore. */
    private const string LEGACY = 'legacy';

    /** The name shown for a price no plan lists anymore. */
    private const string LEGACY_NAME = 'Legacy';

    /** The interval of a price no plan lists anymore. */
    private const string UNKNOWN_INTERVAL = 'unknown';

    /** How many months one of each Stripe billing interval lasts. */
    private const array MONTHS_PER_INTERVAL = ['day' => 12 / 365, 'week' => 12 / 52, 'month' => 1, 'year' => 12];

    /**
     * @return array{plan: string, name: string, interval: string}
     */
    public static function describe(?string $price): array
    {
        $known = data_get(self::byPrice(), (string) $price);

        if (filled($known)) {
            return $known;
        }

        return ['plan' => self::LEGACY, 'name' => self::LEGACY_NAME, 'interval' => self::UNKNOWN_INTERVAL];
    }

    /** The interval of a price a plan lists; null for one no plan lists. */
    public static function intervalOf(?string $price): ?string
    {
        return data_get(self::byPrice(), [(string) $price, 'interval']);
    }

    /**
     * How many months one invoice of a Stripe price covers (its interval
     * times its interval count); null for a price that does not recur.
     */
    public static function monthsPerInvoice(?object $price): ?float
    {
        $months = data_get(self::MONTHS_PER_INTERVAL, (string) data_get($price, 'recurring.interval'));

        if ($months === null) {
            return null;
        }

        return $months * (int) data_get($price, 'recurring.interval_count', 1);
    }

    /**
     * Every plan's prices, by price id, read once per request.
     *
     * @return array<string, array{plan: string, name: string, interval: string}>
     */
    private static function byPrice(): array
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
}
