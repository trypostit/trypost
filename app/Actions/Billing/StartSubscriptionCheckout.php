<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Account;
use App\Models\Plan;
use App\Support\Billing\ConfigureSubscriptionCheckout;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class StartSubscriptionCheckout
{
    /**
     * Stripe metadata values are capped at 500 characters and rejected if longer:
     * https://docs.stripe.com/api/metadata
     */
    public function redirect(Account $account, string $priceId, string $cancelUrl, ?Plan $plan = null): Response
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
            ->withMetadata(array_map(
                fn (string $value): string => Str::limit($value, 500, ''),
                $metadata,
            ));

        ConfigureSubscriptionCheckout::apply(
            $subscription,
            $account,
            self::planForFirstMonthCoupon($plan, $priceId),
        );

        $session = $subscription->checkout([
            'success_url' => route('app.billing.processing').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ]);

        return Inertia::location($session->url);
    }

    private static function planForFirstMonthCoupon(?Plan $plan, string $priceId): ?Plan
    {
        if ($plan === null || $plan->stripe_monthly_price_id !== $priceId) {
            return null;
        }

        return $plan;
    }
}
