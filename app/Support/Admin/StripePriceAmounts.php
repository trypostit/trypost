<?php

declare(strict_types=1);

namespace App\Support\Admin;

use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Cashier;
use Throwable;

/**
 * What a Stripe price charges per month, in cents, read from Stripe once
 * and kept: a price never changes its amount. A yearly price counts a
 * twelfth. Unknown (a self-hosted install, or Stripe unreachable) is null,
 * and is asked again next time.
 */
final class StripePriceAmounts
{
    /** How many months one of each Stripe billing interval lasts. */
    private const array MONTHS_PER_INTERVAL = ['day' => 12 / 365, 'week' => 12 / 52, 'month' => 1, 'year' => 12];

    public static function monthlyCents(string $priceId): ?float
    {
        $key = "admin:stripe-price-monthly:{$priceId}";
        $cached = Cache::get($key);

        if (is_numeric($cached)) {
            return (float) $cached;
        }

        try {
            $price = Cashier::stripe()->prices->retrieve($priceId);
        } catch (Throwable) {
            return null;
        }

        $months = data_get(self::MONTHS_PER_INTERVAL, (string) data_get($price, 'recurring.interval'));

        if ($months === null || ! is_numeric($price->unit_amount)) {
            return null;
        }

        $monthly = (float) $price->unit_amount / ($months * (int) data_get($price, 'recurring.interval_count', 1));
        Cache::forever($key, $monthly);

        return $monthly;
    }
}
