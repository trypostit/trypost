<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Cashier;
use Throwable;

/**
 * What a Stripe price charges per month, in cents, read from Stripe once
 * and kept: a price never changes its amount. A yearly price counts a
 * twelfth. Each price is read once per request; when Stripe cannot tell
 * (unreachable, or a price that is not recurring) the answer is unknown,
 * null, and kept a minute so a page listing many subscriptions does not
 * ask Stripe again for each one.
 */
final class StripePriceAmounts
{
    /** How long an unknown answer is kept before Stripe is asked again, in seconds. */
    public const int UNKNOWN_SECONDS = 60;

    private const string UNKNOWN = 'unknown';

    /**
     * What the subscription charges per month at list price: its price's
     * monthly amount times its quantity, or null when the price is unknown.
     */
    public static function forSubscription(Subscription $subscription): ?float
    {
        if (blank($subscription->stripe_price)) {
            return null;
        }

        $monthly = self::monthlyCents($subscription->stripe_price);

        if ($monthly === null) {
            return null;
        }

        return $monthly * max(1, (int) $subscription->quantity);
    }

    public static function monthlyCents(string $priceId): ?float
    {
        return once(fn (): ?float => self::read($priceId));
    }

    private static function read(string $priceId): ?float
    {
        $key = "admin:stripe-price-monthly:{$priceId}";
        $cached = Cache::get($key);

        if (is_numeric($cached)) {
            return (float) $cached;
        }

        if ($cached === self::UNKNOWN) {
            return null;
        }

        $monthly = self::fromStripe($priceId);

        if ($monthly === null) {
            Cache::put($key, self::UNKNOWN, self::UNKNOWN_SECONDS);

            return null;
        }

        Cache::forever($key, $monthly);

        return $monthly;
    }

    private static function fromStripe(string $priceId): ?float
    {
        try {
            $price = Cashier::stripe()->prices->retrieve($priceId);
        } catch (Throwable) {
            return null;
        }

        $months = data_get(SubscriptionPlans::MONTHS_PER_INTERVAL, (string) data_get($price, 'recurring.interval'));
        $amount = data_get($price, 'unit_amount');

        if ($months === null || ! is_numeric($amount)) {
            return null;
        }

        return (float) $amount / ($months * (int) data_get($price, 'recurring.interval_count', 1));
    }
}
