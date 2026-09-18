<?php

declare(strict_types=1);

namespace App\Enums\Billing;

use App\Models\Plan;

enum Interval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function priceIdFor(Plan $plan): ?string
    {
        return match ($this) {
            self::Monthly => $plan->stripe_monthly_price_id,
            self::Yearly => $plan->stripe_yearly_price_id,
        };
    }
}
