<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Http\Resources\App\SubscriptionDiscountResource;
use App\Models\Account;
use App\Models\Subscription;

class ShowSubscriptionDiscount
{
    /**
     * The coupon on a valid subscription, read from Stripe, so billing can say
     * why the invoice is lower than the plan price.
     *
     * @return array{value: string, duration: string, ends_at: string|null}|null
     */
    public static function execute(Account $account, ?Subscription $subscription): ?array
    {
        if (blank($subscription) || ! $subscription->valid() || ! $account->hasStripeId()) {
            return null;
        }

        $discount = $subscription->discount();

        return filled($discount) ? SubscriptionDiscountResource::make($discount)->resolve() : null;
    }
}
