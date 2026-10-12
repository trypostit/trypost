<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Account;
use App\Models\Plan;
use App\Support\Billing\ConfigureSubscriptionCheckout;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class StartSubscriptionCheckout
{
    public function redirect(Account $account, string $priceId, string $cancelUrl, ?Plan $plan = null): Response
    {
        // A leftover Workspaces row (unpaid/canceled without `deleted`) would
        // let Processing treat auth.plan as checkout-complete and skip the wait.
        if ($account->plan_id !== null) {
            $account->update(['plan_id' => null]);
        }

        $account->syncOrCreateStripeCustomer();

        $monthlyPlan = $this->monthlyPlan($plan, $priceId);
        $subscription = $account->newSubscription(Account::SUBSCRIPTION_NAME, $priceId);

        ConfigureSubscriptionCheckout::apply($subscription, $account, $monthlyPlan);
        $subscription->withMetadata(array_filter([
            ConfigureSubscriptionCheckout::FIRST_MONTH_COUPON_METADATA_KEY => $subscription->couponId,
        ], filled(...)));

        $session = $subscription->checkout([
            'success_url' => route('app.billing.processing').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ]);

        return Inertia::location($session->url);
    }

    private function monthlyPlan(?Plan $plan, string $priceId): ?Plan
    {
        return $plan?->stripe_monthly_price_id === $priceId ? $plan : null;
    }
}
