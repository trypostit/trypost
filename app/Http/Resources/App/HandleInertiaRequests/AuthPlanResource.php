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
        $interval = Interval::forPrice($plan, $account->subscription(Account::SUBSCRIPTION_NAME)?->stripe_price);

        return [
            'id' => $plan->id,
            'slug' => $plan->slug->value,
            'name' => $plan->name,
            'interval' => $interval->value,
        ];
    }
}
