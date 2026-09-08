<?php

declare(strict_types=1);

namespace App\Http\Resources\App\HandleInertiaRequests;

use App\Enums\Billing\Interval;
use App\Models\Account;
use App\Models\Plan;

class AuthPlanResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Account $account, Plan $plan): array
    {
        $subscription = $account->subscription(Account::SUBSCRIPTION_NAME);
        $interval = ($subscription && $subscription->stripe_price === $plan->stripe_yearly_price_id)
            ? Interval::Yearly
            : Interval::Monthly;

        return [
            'id' => $plan->id,
            'slug' => $plan->slug->value,
            'name' => $plan->name,
            'interval' => $interval->value,
        ];
    }
}
