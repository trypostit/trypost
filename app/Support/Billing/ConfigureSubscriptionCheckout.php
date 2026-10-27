<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Offer;
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
     * The offer decides the discount, the trial and the promotion code field;
     * without one the account pays the full price. A coupon wins over the
     * field: Stripe rejects both on one session, never a trial with it.
     * The caller resolves the offer once with the account's current offer, so the coupon, the
     * checkout metadata and the tracked events agree.
     */
    public static function apply(SubscriptionBuilder $subscription, ?Offer $offer = null): SubscriptionBuilder
    {
        if ($offer?->hasTrial()) {
            $subscription->trialDays(max(self::MIN_CHECKOUT_TRIAL_DAYS, $offer->trial_days));
        }

        if (filled($offer?->stripe_coupon_id)) {
            return $subscription->withCoupon($offer->stripe_coupon_id);
        }

        if ($offer?->allow_promotion_codes === true) {
            $subscription->allowPromotionCodes();
        }

        return $subscription;
    }
}
