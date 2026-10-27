<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Account;
use App\Models\Offer;
use App\Models\Plan;
use Laravel\Cashier\SubscriptionBuilder;
use RuntimeException;

final class ConfigureSubscriptionCheckout
{
    public const FIRST_MONTH_COUPON_METADATA_KEY = 'trypost_first_month_coupon_id';

    public const PLAN_METADATA_KEY = 'trypost_plan_slug';

    public const INTERVAL_METADATA_KEY = 'trypost_interval';

    public const FIRST_MONTH_OFFER_METADATA_KEY = 'trypost_first_month_offer';

    public const OFFER_METADATA_KEY = 'trypost_offer_id';

    /**
     * Stripe Checkout rejects subscription trials shorter than 48 hours.
     */
    public const MIN_CHECKOUT_TRIAL_DAYS = 2;

    /**
     * The caller resolves the offer once with offerFor(), so the coupon, the
     * checkout metadata and the tracked events agree.
     *
     * @throws RuntimeException when a coupon would apply with allow_promotion_codes enabled
     */
    public static function apply(SubscriptionBuilder $subscription, Account $account, ?Plan $plan = null, ?Offer $offer = null): SubscriptionBuilder
    {
        if (filled($offer)) {
            self::assertPromotionCodesOff('an offer coupon');

            return $subscription->withCoupon($offer->stripe_coupon_id);
        }

        $couponId = self::firstMonthCouponId($account, $plan);

        if ($couponId !== null) {
            self::assertPromotionCodesOff('a first-month coupon');

            return $subscription->withCoupon($couponId);
        }

        if (
            (bool) config('trypost.billing.require_card_for_trial', true)
            && self::isFirstTimeSubscriber($account)
        ) {
            $trialDays = (int) config('cashier.trial_days');

            if ($trialDays > 0) {
                $subscription->trialDays(max(self::MIN_CHECKOUT_TRIAL_DAYS, $trialDays));
            }
        }

        if ((bool) config('cashier.allow_promotion_codes', false)) {
            $subscription->allowPromotionCodes();
        }

        return $subscription;
    }

    public static function offersFirstMonthCoupon(Account $account, Plan $plan, ?Offer $offer = null): bool
    {
        return blank($offer) && self::firstMonthCouponId($account, $plan) !== null;
    }

    /**
     * The offer the Welcome checkout applies. It needs the monthly plan,
     * which only the Welcome checkout passes.
     */
    public static function offerFor(Account $account, ?Plan $plan): ?Offer
    {
        return filled($plan) ? $account->currentOffer() : null;
    }

    /**
     * @throws RuntimeException when allow_promotion_codes is enabled
     */
    private static function assertPromotionCodesOff(string $coupon): void
    {
        if ((bool) config('cashier.allow_promotion_codes', false)) {
            throw new RuntimeException(
                "Cannot apply {$coupon} while CASHIER_ALLOW_PROMOTION_CODES is enabled: "
                .'Stripe Checkout rejects discounts and allow_promotion_codes on the same session.'
            );
        }
    }

    private static function firstMonthCouponId(Account $account, ?Plan $plan): ?string
    {
        if ($plan === null || ! (bool) config('trypost.billing.require_card_for_trial', true)) {
            return null;
        }

        if (! self::isFirstTimeSubscriber($account)) {
            return null;
        }

        $couponId = config("cashier.first_month_coupon_ids.{$plan->slug->value}");

        if (! is_string($couponId) || $couponId === '') {
            return null;
        }

        return $couponId;
    }

    /** Incomplete / incomplete_expired never became billable, so retries still count as first-time. */
    private static function isFirstTimeSubscriber(Account $account): bool
    {
        return ! $account->hasHadBillableSubscription();
    }
}
