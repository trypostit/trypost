<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Reads the raw values of a Stripe payload, null when a value is missing or
 * not of the type Stripe sends.
 */
final class StripeValue
{
    /**
     * Stripe sends amounts in cents; TryPost charges in BRL, USD and EUR only,
     * all with two decimals.
     */
    public static function amount(mixed $cents): ?float
    {
        return is_int($cents) ? $cents / 100 : null;
    }

    public static function currency(mixed $currency): ?string
    {
        return is_string($currency) && filled($currency) ? Str::upper($currency) : null;
    }

    public static function timestamp(mixed $seconds): ?CarbonImmutable
    {
        return is_numeric($seconds) ? CarbonImmutable::createFromTimestampUTC((int) $seconds) : null;
    }
}
