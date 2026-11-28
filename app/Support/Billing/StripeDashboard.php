<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Illuminate\Support\Str;

/**
 * Links into the Stripe dashboard, in test mode when the app runs on Stripe
 * test keys.
 */
final class StripeDashboard
{
    public static function customerUrl(?string $stripeId): ?string
    {
        if (blank($stripeId)) {
            return null;
        }

        if (Str::startsWith((string) config('cashier.secret'), 'sk_test_')) {
            return "https://dashboard.stripe.com/test/customers/{$stripeId}";
        }

        return "https://dashboard.stripe.com/customers/{$stripeId}";
    }
}
