<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Account;
use Laravel\Cashier\SubscriptionBuilder;
use RuntimeException;
use Stripe\Subscription as StripeSubscription;

final class FirstMonthCheckoutDiscount
{
    /**
     * Configure a subscription checkout to charge $1 for the first invoice via
     * a `duration: once` Stripe coupon, so a real charge validates the card
     * instead of a $0 trial authorization. Stripe rejects a Checkout Session
     * that sets both `discounts` and `allow_promotion_codes`, so checkouts that
     * don't qualify for the paid first month keep the promotion-code field.
     *
     * @throws RuntimeException when a qualifying checkout has the paid first
     *                          month enabled but no coupon configured — failing
     *                          loudly beats silently charging full price.
     */
    public static function apply(SubscriptionBuilder $subscription, Account $account): SubscriptionBuilder
    {
        if (! self::qualifiesForPaidFirstMonth($account)) {
            return $subscription->allowPromotionCodes();
        }

        $couponId = config('cashier.first_month_coupon_id');

        if (! is_string($couponId) || $couponId === '') {
            throw new RuntimeException(
                'STRIPE_FIRST_MONTH_COUPON_ID must be set when REQUIRE_CARD_FOR_TRIAL is enabled, '
                .'otherwise checkout would charge the full price instead of the $1 first month.'
            );
        }

        return $subscription->withCoupon($couponId);
    }

    /**
     * The fixed-amount first-month coupon only applies to a genuinely new
     * customer checking out a single workspace: the fixed `amount_off` is only
     * correct for a quantity of one, and the $1 offer is for first-time signups
     * — not a returning account re-subscribing with workspaces it kept from a
     * lapsed subscription. A subscription that never left `incomplete` never
     * became real, so a new customer retrying after a failed first attempt
     * still qualifies; any started subscription (even canceled) does not.
     */
    private static function qualifiesForPaidFirstMonth(Account $account): bool
    {
        if (! (bool) config('trypost.billing.require_card_for_trial', true)) {
            return false;
        }

        return $account->workspaces()->count() === 1
            && ! $account->subscriptions()
                ->whereNotIn('stripe_status', [
                    StripeSubscription::STATUS_INCOMPLETE,
                    StripeSubscription::STATUS_INCOMPLETE_EXPIRED,
                ])
                ->exists();
    }
}
