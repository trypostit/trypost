<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Account;
use App\Models\Plan;
use Laravel\Cashier\SubscriptionBuilder;
use RuntimeException;
use Stripe\Subscription as StripeSubscription;

final class ConfigureSubscriptionCheckout
{
    /**
     * Stripe Checkout rejects subscription trials shorter than 48 hours.
     */
    public const MIN_CHECKOUT_TRIAL_DAYS = 2;

    /**
     * @throws RuntimeException when a coupon would apply with allow_promotion_codes enabled
     */
    public static function apply(SubscriptionBuilder $subscription, Account $account, ?Plan $plan = null): SubscriptionBuilder
    {
        $couponId = self::firstMonthCouponId($account, $plan);

        if ($couponId !== null) {
            if ((bool) config('cashier.allow_promotion_codes', false)) {
                throw new RuntimeException(
                    'Cannot apply a first-month coupon while CASHIER_ALLOW_PROMOTION_CODES is enabled: '
                    .'Stripe Checkout rejects discounts and allow_promotion_codes on the same session.'
                );
            }

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
        return ! $account->subscriptions()
            ->whereNotIn('stripe_status', [
                StripeSubscription::STATUS_INCOMPLETE,
                StripeSubscription::STATUS_INCOMPLETE_EXPIRED,
            ])
            ->exists();
    }
}
