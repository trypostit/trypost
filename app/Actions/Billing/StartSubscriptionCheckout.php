<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Account;
use App\Support\Billing\ConfigureSubscriptionCheckout;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class StartSubscriptionCheckout
{
    /**
     * Create a Stripe Checkout session for the given price and return an Inertia
     * redirect to it. Quantity tracks the account's workspace count. Trial days,
     * optional first-month coupon, and promotion codes come from cashier /
     * trypost billing env config via ConfigureSubscriptionCheckout. The owner's
     * signup attribution -- UTM parameters and ad click IDs -- and onboarding
     * answers ride along as subscription metadata, flattened to the strings
     * Stripe stores and cut to the 500 characters it allows per value. Stripe
     * rejects a longer value outright rather than truncating it, which would
     * fail the whole checkout: https://docs.stripe.com/api/metadata
     */
    public function redirect(Account $account, string $priceId, string $cancelUrl): Response
    {
        $account->createOrGetStripeCustomer([
            'email' => $account->stripeEmail(),
            'name' => $account->stripeName(),
        ]);

        $owner = $account->owner;

        $metadata = array_filter([
            'utm_source' => $owner?->utm_source,
            'utm_medium' => $owner?->utm_medium,
            'utm_campaign' => $owner?->utm_campaign,
            'utm_term' => $owner?->utm_term,
            'utm_content' => $owner?->utm_content,
            'gclid' => $owner?->gclid,
            'fbclid' => $owner?->fbclid,
            'li_fat_id' => $owner?->li_fat_id,
            'ttclid' => $owner?->ttclid,
            'rdt_cid' => $owner?->rdt_cid,
            'epik' => $owner?->epik,
            'persona' => $owner?->persona?->value,
            'goals' => implode(',', $owner?->goals ?? []),
            'referral_source' => $owner?->referral_source?->value,
        ]);

        $subscription = $account->newSubscription(Account::SUBSCRIPTION_NAME, $priceId)
            ->quantity(max(1, $account->workspaces()->count()))
            ->withMetadata(array_map(
                fn (string $value): string => Str::limit($value, 500, ''),
                $metadata,
            ));

        ConfigureSubscriptionCheckout::apply($subscription, $account);

        $session = $subscription->checkout([
            'success_url' => route('app.billing.processing').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ]);

        return Inertia::location($session->url);
    }
}
