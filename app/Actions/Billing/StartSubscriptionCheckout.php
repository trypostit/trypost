<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Billing\Interval;
use App\Models\Account;
use App\Models\Offer;
use App\Models\Plan;
use App\Support\Billing\ConfigureSubscriptionCheckout;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class StartSubscriptionCheckout
{
    /**
     * @param  bool  $fromBilling  the owner returns to billing after paying, not to the Welcome steps
     */
    public function redirect(Account $account, string $priceId, string $cancelUrl, ?Plan $plan = null, ?Offer $offer = null, bool $fromBilling = false): Response
    {
        // A leftover Workspaces row (unpaid/canceled without `deleted`) would
        // let Processing treat auth.plan as checkout-complete and skip the wait.
        // A generic trial's plan is current, so it stays.
        if ($account->plan_id !== null && ! $account->onGenericTrial()) {
            $account->update(['plan_id' => null]);
        }

        $account->syncOrCreateStripeCustomer();

        $monthlyPlan = $this->monthlyPlan($plan, $priceId);
        $subscription = $account->newSubscription(Account::SUBSCRIPTION_NAME, $priceId);

        $offer = filled($monthlyPlan) ? $offer : null;

        $offerMetadata = array_filter([
            ConfigureSubscriptionCheckout::OFFER_METADATA_KEY => $offer?->id,
            ConfigureSubscriptionCheckout::OFFER_SLUG_METADATA_KEY => $offer?->slug,
        ], filled(...));

        ConfigureSubscriptionCheckout::apply($subscription, $offer);

        if ($account->onGenericTrial() && ! $offer?->hasTrial()) {
            $subscription->trialUntil($account->trial_ends_at);
        }
        $subscription->withMetadata($offerMetadata);

        $session = $subscription->checkout([
            'success_url' => route('app.billing.processing', ['from' => $fromBilling ? 'billing' : 'welcome']).'&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'metadata' => array_filter([
                ConfigureSubscriptionCheckout::PLAN_METADATA_KEY => $plan?->slug->value,
                ConfigureSubscriptionCheckout::INTERVAL_METADATA_KEY => (filled($plan) ? Interval::forPrice($plan, $priceId) : Interval::Monthly)->value,
                ConfigureSubscriptionCheckout::OFFER_SLUG_METADATA_KEY => $offer?->slug,
            ], filled(...)),
        ]);

        return Inertia::location($session->url);
    }

    private function monthlyPlan(?Plan $plan, string $priceId): ?Plan
    {
        return $plan?->stripe_monthly_price_id === $priceId ? $plan : null;
    }
}
