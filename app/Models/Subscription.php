<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Cashier\Subscription as CashierSubscription;
use Stripe\Subscription as StripeSubscription;

class Subscription extends CashierSubscription
{
    use HasUuids;

    protected $casts = [
        'ends_at' => 'datetime',
        'first_month_offer_ends_at' => 'datetime',
        'quantity' => 'integer',
        'stripe_started_at' => 'datetime',
        'trial_ends_at' => 'datetime',
    ];

    public function isInFirstMonthOffer(): bool
    {
        return $this->stripe_status === StripeSubscription::STATUS_ACTIVE
            && filled($this->first_month_coupon_id)
            && $this->first_month_offer_ends_at?->isFuture() === true;
    }
}
