<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Where a Stripe Checkout started, so Processing returns the owner there.
 */
enum CheckoutOrigin: string
{
    case Welcome = 'welcome';
    case Billing = 'billing';
}
