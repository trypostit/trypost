<?php

declare(strict_types=1);

use App\Models\Account;
use App\Support\Billing\SubscriptionAnalytics;
use Stripe\Coupon;
use Stripe\Discount;
use Stripe\Subscription as StripeSubscription;

test('account properties explicitly clear subscription analytics when there is no subscription', function () {
    expect((new SubscriptionAnalytics)->properties(null))->toBe([
        'subscription_status' => null,
        'subscription_started_at' => null,
        'subscription_coupon_id' => null,
        'is_in_first_month_offer' => false,
        'first_month_offer_ends_at' => null,
    ]);
});

test('stripe backfill detects a configured first month coupon from expanded discounts', function () {
    config()->set('cashier.first_month_coupon_ids.socials', 'SOCIALS_18USD');

    $account = Account::factory()->create();
    $subscription = $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test123',
        'stripe_status' => StripeSubscription::STATUS_ACTIVE,
        'stripe_price' => 'price_socials_monthly',
    ]);
    $startedAt = now()->startOfSecond();
    $endsAt = $startedAt->copy()->addMonth();
    $stripeSubscription = StripeSubscription::constructFrom([
        'id' => 'sub_test123',
        'start_date' => $startedAt->timestamp,
        'items' => ['data' => [[
            'current_period_start' => $startedAt->timestamp,
            'current_period_end' => $endsAt->timestamp,
        ]]],
    ]);
    $stripeSubscription->discounts = [Discount::constructFrom([
        'id' => 'di_test123',
        'coupon' => Coupon::constructFrom(['id' => 'SOCIALS_18USD']),
    ])];

    (new SubscriptionAnalytics)->syncFromStripe($subscription, $stripeSubscription);

    $subscription->refresh();

    expect($subscription->stripe_started_at?->toDateTimeString())->toBe($startedAt->toDateTimeString())
        ->and($subscription->first_month_coupon_id)->toBe('SOCIALS_18USD')
        ->and($subscription->first_month_offer_ends_at?->toDateTimeString())->toBe($endsAt->toDateTimeString())
        ->and($subscription->isInFirstMonthOffer())->toBeTrue();
});

test('stripe backfill ignores unrelated coupons', function () {
    config()->set('cashier.first_month_coupon_ids.socials', 'SOCIALS_18USD');

    $account = Account::factory()->create();
    $subscription = $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test123',
        'stripe_status' => StripeSubscription::STATUS_ACTIVE,
        'stripe_price' => 'price_socials_monthly',
    ]);
    $startedAt = now()->startOfSecond();
    $stripeSubscription = StripeSubscription::constructFrom([
        'id' => 'sub_test123',
        'start_date' => $startedAt->timestamp,
        'items' => ['data' => [[
            'current_period_start' => $startedAt->timestamp,
            'current_period_end' => $startedAt->copy()->addMonth()->timestamp,
        ]]],
    ]);
    $stripeSubscription->discounts = [Discount::constructFrom([
        'id' => 'di_test123',
        'coupon' => Coupon::constructFrom(['id' => 'UNRELATED_COUPON']),
    ])];

    (new SubscriptionAnalytics)->syncFromStripe($subscription, $stripeSubscription);

    expect($subscription->fresh()->first_month_coupon_id)->toBeNull();
});
