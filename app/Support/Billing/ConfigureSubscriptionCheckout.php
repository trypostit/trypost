<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Account;
use App\Models\Offer;
use App\Models\Plan;
use Laravel\Cashier\SubscriptionBuilder;

final class ConfigureSubscriptionCheckout
{
    public const PLAN_METADATA_KEY = 'trypost_plan_slug';

    public const INTERVAL_METADATA_KEY = 'trypost_interval';

    public const OFFER_METADATA_KEY = 'trypost_offer_id';

    public const OFFER_SLUG_METADATA_KEY = 'trypost_offer_slug';

    /**
     * Stripe Checkout rejects subscription trials shorter than 48 hours.
     */
    public const MIN_CHECKOUT_TRIAL_DAYS = 2;

    /**
     * An offer applies its coupon. Without one, a first-time subscriber gets
     * the trial and the promotion code field may show: Stripe rejects a
     * coupon and promotion codes on the same session, never a trial with them.
     * The caller resolves the offer once with offerFor(), so the coupon, the
     * checkout metadata and the tracked events agree.
     */
    public static function apply(SubscriptionBuilder $subscription, Account $account, ?Offer $offer = null): SubscriptionBuilder
    {
        if (filled($offer)) {
            return $subscription->withCoupon($offer->stripe_coupon_id);
        }

        $trialDays = self::trialDaysFor($account);

        if ($trialDays > 0) {
            $subscription->trialDays($trialDays);
        }

        if ((bool) config('cashier.allow_promotion_codes', false)) {
            $subscription->allowPromotionCodes();
        }

        return $subscription;
    }

    /**
     * The trial a checkout without an offer gets: first-time subscribers only,
     * with a card at checkout. 0 means no trial.
     */
    public static function trialDaysFor(Account $account): int
    {
        $trialDays = (int) config('cashier.trial_days');

        if (! (bool) config('trypost.billing.require_card_for_trial', true) || $trialDays <= 0 || $account->hasHadBillableSubscription()) {
            return 0;
        }

        return max(self::MIN_CHECKOUT_TRIAL_DAYS, $trialDays);
    }

    /**
     * The offer the Welcome checkout applies. It needs the monthly plan,
     * which only the Welcome checkout passes, and a card at checkout.
     */
    public static function offerFor(Account $account, ?Plan $plan): ?Offer
    {
        if (blank($plan) || ! (bool) config('trypost.billing.require_card_for_trial', true)) {
            return null;
        }

        return $account->currentOffer();
    }
}
