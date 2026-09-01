<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Account;
use App\Support\Billing\ConfigureSubscriptionCheckout;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class StartSubscriptionCheckout
{
    /**
     * Create a Stripe Checkout session for the given price and return an Inertia
     * redirect to it. Quantity tracks the account's workspace count. Trial days,
     * optional first-month coupon, and promotion codes come from cashier /
     * trypost billing env config via ConfigureSubscriptionCheckout. The owner's
     * signup attribution and onboarding answers ride along as subscription
     * metadata, flattened to the strings Stripe accepts.
     */
    public function redirect(Account $account, string $priceId, string $cancelUrl): Response
    {
        $account->createOrGetStripeCustomer([
            'email' => $account->stripeEmail(),
            'name' => $account->stripeName(),
        ]);

        $owner = $account->owner;

        $subscription = $account->newSubscription(Account::SUBSCRIPTION_NAME, $priceId)
            ->quantity(max(1, $account->workspaces()->count()))
            ->withMetadata(array_filter([
                'utm_source' => $owner?->utm_source,
                'utm_medium' => $owner?->utm_medium,
                'utm_campaign' => $owner?->utm_campaign,
                'utm_term' => $owner?->utm_term,
                'utm_content' => $owner?->utm_content,
                'persona' => $owner?->persona?->value,
                'goals' => implode(',', $owner?->goals ?? []),
                'referral_source' => $owner?->referral_source?->value,
            ]));

        ConfigureSubscriptionCheckout::apply($subscription, $account);

        $session = $subscription->checkout([
            'success_url' => route('app.billing.processing').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ]);

        return Inertia::location($session->url);
    }
}
