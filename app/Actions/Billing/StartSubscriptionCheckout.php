<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Billing\CheckoutOrigin;
use App\Enums\Billing\Interval;
use App\Enums\PostHog\CheckoutEvent;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Models\Account;
use App\Models\Offer;
use App\Models\Plan;
use App\Services\PostHogService;
use App\Support\Billing\ConfigureSubscriptionCheckout;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class StartSubscriptionCheckout
{
    public function redirect(Account $account, string $priceId, string $cancelUrl, ?Plan $plan = null, ?Offer $offer = null, CheckoutOrigin $origin = CheckoutOrigin::Welcome): Response
    {
        // A leftover Workspaces row (unpaid/canceled without `deleted`) would
        // let Processing treat auth.plan as checkout-complete and skip the wait.
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

        $subscription->withMetadata($offerMetadata);

        $interval = filled($plan) ? Interval::forPrice($plan, $priceId) : Interval::Monthly;

        $session = $subscription->checkout([
            'success_url' => route('app.billing.processing', ['from' => $origin->value]).'&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'metadata' => array_filter([
                ConfigureSubscriptionCheckout::PLAN_METADATA_KEY => $plan?->slug->value,
                ConfigureSubscriptionCheckout::INTERVAL_METADATA_KEY => $interval->value,
                ConfigureSubscriptionCheckout::OFFER_SLUG_METADATA_KEY => $offer?->slug,
            ], filled(...)),
        ]);

        rescue(function () use ($account, $plan, $interval, $offer): void {
            CaptureAccountEvent::dispatchIf(
                PostHogService::isEnabled() && filled($account->owner_id),
                (string) $account->owner_id,
                (string) $account->id,
                CheckoutEvent::Started,
                [
                    'plan_name' => $plan?->name,
                    'interval' => $interval->value,
                    'offer' => $offer?->slug,
                ],
            );
        });

        return Inertia::location($session->url);
    }

    private function monthlyPlan(?Plan $plan, string $priceId): ?Plan
    {
        return $plan?->stripe_monthly_price_id === $priceId ? $plan : null;
    }
}
