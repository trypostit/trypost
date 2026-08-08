<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Account;
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
     * Apply env-driven checkout options to a subscription builder.
     *
     * Precedence when REQUIRE_CARD_FOR_TRIAL is enabled:
     * 1. Qualifying first-month coupon → withCoupon, no trialDays (card charge now).
     * 2. Else first-time customer + CASHIER_TRIAL_DAYS > 0 → trialDays (clamped to ≥ 2).
     * 3. Else plain checkout (immediate full price) — including re-subscribers.
     *
     * CASHIER_ALLOW_PROMOTION_CODES enables the Checkout promo field only when no
     * coupon is applied — Stripe rejects discounts + allow_promotion_codes together.
     *
     * @throws RuntimeException when a coupon would be applied while
     *                          allow_promotion_codes is also enabled.
     */
    public static function apply(SubscriptionBuilder $subscription, Account $account): SubscriptionBuilder
    {
        if (self::shouldApplyFirstMonthCoupon($account)) {
            if ((bool) config('cashier.allow_promotion_codes', false)) {
                throw new RuntimeException(
                    'Cannot apply STRIPE_FIRST_MONTH_COUPON_ID while CASHIER_ALLOW_PROMOTION_CODES is enabled: '
                    .'Stripe Checkout rejects discounts and allow_promotion_codes on the same session.'
                );
            }

            return $subscription->withCoupon((string) config('cashier.first_month_coupon_id'));
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

    /**
     * Fixed amount_off first-month coupons only fit a new customer checking out a
     * single workspace. A subscription that never left incomplete never became
     * real, so a retry after a failed first attempt still qualifies; any started
     * subscription (even canceled) does not.
     */
    private static function shouldApplyFirstMonthCoupon(Account $account): bool
    {
        if (! (bool) config('trypost.billing.require_card_for_trial', true)) {
            return false;
        }

        $couponId = config('cashier.first_month_coupon_id');

        if (! is_string($couponId) || $couponId === '') {
            return false;
        }

        return $account->workspaces()->count() === 1
            && self::isFirstTimeSubscriber($account);
    }

    /**
     * True when the account has never had a real Stripe subscription. Rows that
     * stayed in incomplete / incomplete_expired never became billable, so a
     * retry after a failed first Checkout still counts as first-time.
     */
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
