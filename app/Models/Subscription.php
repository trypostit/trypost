<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Cashier\Subscription as CashierSubscription;
use Stripe\Subscription as StripeSubscription;

class Subscription extends CashierSubscription
{
    use HasUuids;

    /** Incomplete / incomplete_expired never became billable. */
    public function becameBillable(): bool
    {
        return ! $this->incomplete() && $this->stripe_status !== StripeSubscription::STATUS_INCOMPLETE_EXPIRED;
    }
}
