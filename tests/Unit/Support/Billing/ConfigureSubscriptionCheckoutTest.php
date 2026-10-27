<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Models\Account;
use App\Models\Offer;
use App\Models\Plan;
use App\Support\Billing\ConfigureSubscriptionCheckout;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\SubscriptionBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->account = Account::factory()->create();
});

function givePriorSubscription(Account $account, string $status = 'canceled'): void
{
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => $status,
        'stripe_price' => 'price_monthly_test',
    ]);
    $account->unsetRelation('subscriptions');
}

function trialExpiresAt(SubscriptionBuilder $subscription): ?Carbon
{
    $property = (new ReflectionClass($subscription))->getProperty('trialExpires');

    return $property->getValue($subscription);
}

function socialsPlan(): Plan
{
    return Plan::where('slug', Slug::Socials)->firstOrFail();
}

function claimOfferFor(Account $account, Offer $offer): Offer
{
    $account->offers()->attach($offer, ['claimed_at' => now()]);

    return $offer;
}

function configureCheckout(Account $account): SubscriptionBuilder
{
    $subscription = $account->newSubscription(Account::SUBSCRIPTION_NAME, 'price_monthly_test');

    ConfigureSubscriptionCheckout::apply($subscription, ConfigureSubscriptionCheckout::offerFor($account, socialsPlan()));

    return $subscription;
}

test('without an offer the checkout charges the full price at once', function () {
    $subscription = configureCheckout($this->account);

    expect($subscription->couponId)->toBeNull()
        ->and(trialExpiresAt($subscription))->toBeNull()
        ->and($subscription->allowPromotionCodes)->toBeFalse();
});

test('an offer that allows promotion codes shows the field', function () {
    claimOfferFor($this->account, Offer::factory()->trial(8)->allowingPromotionCodes()->create());

    expect(configureCheckout($this->account)->allowPromotionCodes)->toBeTrue();
});

test('a trial offer gives a first-time subscriber its trial', function () {
    Carbon::setTestNow('2026-08-07 12:00:00');
    claimOfferFor($this->account, Offer::factory()->trial(8)->firstSubscriptionOnly()->create());

    $subscription = configureCheckout($this->account);

    expect($subscription->couponId)->toBeNull()
        ->and(trialExpiresAt($subscription)?->toDateTimeString())->toBe('2026-08-15 12:00:00');
});

test('a former customer gets no first-subscription trial', function () {
    claimOfferFor($this->account, Offer::factory()->trial(8)->firstSubscriptionOnly()->create());
    givePriorSubscription($this->account);

    expect(trialExpiresAt(configureCheckout($this->account)))->toBeNull();
});

test('a checkout that never became billable still counts as first-time', function (string $status) {
    claimOfferFor($this->account, Offer::factory()->trial(8)->firstSubscriptionOnly()->create());
    givePriorSubscription($this->account, $status);

    expect(trialExpiresAt(configureCheckout($this->account)))->not->toBeNull();
})->with(['incomplete', 'incomplete_expired']);

test('a trial offer keeps the promotion code field, which Stripe accepts with a trial', function () {
    claimOfferFor($this->account, Offer::factory()->trial(8)->allowingPromotionCodes()->create());

    $subscription = configureCheckout($this->account);

    expect($subscription->allowPromotionCodes)->toBeTrue()
        ->and(trialExpiresAt($subscription))->not->toBeNull();
});

test('a coupon wins over the promotion code field, which Stripe rejects with it', function () {
    claimOfferFor($this->account, Offer::factory()->allowingPromotionCodes()->create(['stripe_coupon_id' => 'BF26_50']));

    $subscription = configureCheckout($this->account);

    expect($subscription->couponId)->toBe('BF26_50')
        ->and($subscription->allowPromotionCodes)->toBeFalse()
        ->and(trialExpiresAt($subscription))->toBeNull();
});

test('an offer can carry a coupon and a trial together', function () {
    claimOfferFor($this->account, Offer::factory()->create(['stripe_coupon_id' => 'BF26_50', 'trial_days' => 8]));

    $subscription = configureCheckout($this->account);

    expect($subscription->couponId)->toBe('BF26_50')
        ->and(trialExpiresAt($subscription))->not->toBeNull();
});

test('clamps a one-day trial to the Stripe Checkout minimum of two days', function () {
    Carbon::setTestNow('2026-08-07 12:00:00');
    claimOfferFor($this->account, Offer::factory()->trial(1)->create());

    expect(trialExpiresAt(configureCheckout($this->account))?->toDateTimeString())->toBe('2026-08-09 12:00:00');
});

test('an offer applies to a former customer', function () {
    givePriorSubscription($this->account);
    claimOfferFor($this->account, Offer::factory()->create(['stripe_coupon_id' => 'BF26_50']));

    expect(configureCheckout($this->account)->couponId)->toBe('BF26_50');
});

test('a first-subscription offer skips an account that was billed before', function () {
    claimOfferFor($this->account, Offer::factory()->firstSubscriptionOnly()->create(['stripe_coupon_id' => 'FIRST_MONTH_1']));

    expect(configureCheckout($this->account)->couponId)->toBe('FIRST_MONTH_1');

    givePriorSubscription($this->account);

    expect(ConfigureSubscriptionCheckout::offerFor($this->account, socialsPlan()))->toBeNull();
});

test('does not apply an offer without a plan', function () {
    claimOfferFor($this->account, Offer::factory()->trial(8)->create());

    expect(ConfigureSubscriptionCheckout::offerFor($this->account, null))->toBeNull();
});

test('does not apply an expired offer', function () {
    claimOfferFor($this->account, Offer::factory()->expired()->create(['stripe_coupon_id' => 'BF26_50']));

    expect(configureCheckout($this->account)->couponId)->toBeNull();
});

test('an offer with zero trial days gives no trial', function () {
    claimOfferFor($this->account, Offer::factory()->trial(0)->create());

    expect(trialExpiresAt(configureCheckout($this->account)))->toBeNull();
});
