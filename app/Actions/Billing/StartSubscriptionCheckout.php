<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Account;
use App\Support\Billing\FirstMonthCheckoutDiscount;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class StartSubscriptionCheckout
{
    /**
     * Create a Stripe Checkout session for the given price and return an Inertia
     * redirect to it. Quantity tracks the account's workspace count; the first
     * month's coupon is applied when the instance requires a card up front, so
     * the first invoice charges $1 instead of running a $0 trial authorization.
     */
    public function redirect(Account $account, string $priceId, string $cancelUrl): Response
    {
        $account->createOrGetStripeCustomer([
            'email' => $account->stripeEmail(),
            'name' => $account->stripeName(),
        ]);

        $subscription = $account->newSubscription(Account::SUBSCRIPTION_NAME, $priceId)
            ->quantity(max(1, $account->workspaces()->count()));

        FirstMonthCheckoutDiscount::apply($subscription, $account);

        $session = $subscription->checkout([
            'success_url' => route('app.billing.processing').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ]);

        return Inertia::location($session->url);
    }
}
