<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Illuminate\Support\Str;

final class StripeAmount
{
    /**
     * Currencies Stripe charges in their main unit, not in cents.
     *
     * @see https://docs.stripe.com/currencies#zero-decimal
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * A Stripe amount in its currency's main unit, or null when the payload
     * has no amount or no currency.
     */
    public static function toDecimal(mixed $amount, mixed $currency): ?float
    {
        if (! is_int($amount) || ! is_string($currency) || blank($currency)) {
            return null;
        }

        return in_array(Str::upper($currency), self::ZERO_DECIMAL_CURRENCIES, true)
            ? (float) $amount
            : $amount / 100;
    }
}
