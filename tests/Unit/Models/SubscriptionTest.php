<?php

declare(strict_types=1);

use App\Models\Account;
use Stripe\Subscription as StripeSubscription;

test('active subscription with an unexpired configured offer is in its first month', function () {
    $account = Account::factory()->create();
    $subscription = $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_first_month',
        'stripe_status' => StripeSubscription::STATUS_ACTIVE,
        'stripe_price' => 'price_socials_monthly',
        'first_month_coupon_id' => 'SOCIALS_18USD',
        'first_month_offer_ends_at' => now()->addDay(),
    ]);

    expect($subscription->isInFirstMonthOffer())->toBeTrue();
});

test('first month offer is false when expired, absent, or the subscription is not active', function (array $attributes) {
    $account = Account::factory()->create();
    $subscription = $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => StripeSubscription::STATUS_ACTIVE,
        'stripe_price' => 'price_socials_monthly',
        'first_month_coupon_id' => 'SOCIALS_18USD',
        'first_month_offer_ends_at' => now()->addDay(),
        ...$attributes,
    ]);

    expect($subscription->isInFirstMonthOffer())->toBeFalse();
})->with([
    'expired' => [['first_month_offer_ends_at' => now()->subSecond()]],
    'without coupon' => [['first_month_coupon_id' => null]],
    'stripe trial' => [['stripe_status' => StripeSubscription::STATUS_TRIALING]],
    'canceled' => [['stripe_status' => StripeSubscription::STATUS_CANCELED]],
    'past due' => [['stripe_status' => StripeSubscription::STATUS_PAST_DUE]],
]);
