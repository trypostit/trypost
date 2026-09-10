<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Stripe\Subscription as StripeSubscription;

final class SubscriptionPlanSync
{
    public static function allows(mixed $status): bool
    {
        return in_array($status, [
            StripeSubscription::STATUS_ACTIVE,
            StripeSubscription::STATUS_TRIALING,
        ], true);
    }

    /**
     * These statuses mean the paid subscription is gone. `past_due` and
     * `incomplete` are left alone: dunning still has app access, and 3DS
     * must not wipe a plan the account already earned.
     */
    public static function clears(mixed $status): bool
    {
        return in_array($status, [
            StripeSubscription::STATUS_UNPAID,
            StripeSubscription::STATUS_CANCELED,
            StripeSubscription::STATUS_INCOMPLETE_EXPIRED,
        ], true);
    }
}
