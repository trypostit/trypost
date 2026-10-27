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

    config([
        'trypost.billing.require_card_for_trial' => true,
        'cashier.trial_days' => 8,
        'cashier.allow_promotion_codes' => false,
    ]);
});

function checkoutSubscription(Account $account): SubscriptionBuilder
{
    return $account->newSubscription(Account::SUBSCRIPTION_NAME, 'price_monthly_test');
}

function givePriorSubscription(Account $account, string $status = 'canceled'): void
{
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => $status,
        'stripe_price' => 'price_monthly_test',
    ]);
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

/**
 * @param  array<string, mixed>  $attributes
 */
function claimOfferFor(Account $account, array $attributes = []): Offer
{
    $offer = Offer::factory()->create(['stripe_coupon_id' => 'BF26_50', ...$attributes]);
    $account->offers()->attach($offer, ['claimed_at' => now()]);

    return $offer;
}

function configureCheckout(Account $account): SubscriptionBuilder
{
    $subscription = checkoutSubscription($account);

    ConfigureSubscriptionCheckout::apply($subscription, $account, ConfigureSubscriptionCheckout::offerFor($account, socialsPlan()));

    return $subscription;
}

test('without an offer a first-time subscriber gets an eight-day trial and no promotion codes', function () {
    Carbon::setTestNow('2026-08-07 12:00:00');

    $subscription = configureCheckout($this->account);

    expect($subscription->couponId)->toBeNull()
        ->and($subscription->allowPromotionCodes)->toBeFalse()
        ->and(trialExpiresAt($subscription)?->toDateTimeString())->toBe('2026-08-15 12:00:00');
});

test('without an offer the trial comes with the promotion code field when enabled', function () {
    config(['cashier.allow_promotion_codes' => true]);

    $subscription = configureCheckout($this->account);

    expect($subscription->allowPromotionCodes)->toBeTrue()
        ->and(trialExpiresAt($subscription))->not->toBeNull();
});

test('an offer applies its coupon with neither a trial nor the promotion code field', function () {
    config(['cashier.allow_promotion_codes' => true]);
    claimOfferFor($this->account);

    $subscription = configureCheckout($this->account);

    expect($subscription->couponId)->toBe('BF26_50')
        ->and($subscription->allowPromotionCodes)->toBeFalse()
        ->and(trialExpiresAt($subscription))->toBeNull();
});

test('a former customer without an offer gets no trial', function () {
    givePriorSubscription($this->account);

    $subscription = configureCheckout($this->account);

    expect($subscription->couponId)->toBeNull()
        ->and(trialExpiresAt($subscription))->toBeNull()
        ->and(ConfigureSubscriptionCheckout::trialDaysFor($this->account))->toBe(0);
});

test('a checkout that never became billable still counts as first-time', function (string $status) {
    givePriorSubscription($this->account, $status);

    expect(ConfigureSubscriptionCheckout::trialDaysFor($this->account))->toBe(8);
})->with(['incomplete', 'incomplete_expired']);

test('without a card at checkout there is neither a trial nor an offer', function () {
    config(['trypost.billing.require_card_for_trial' => false, 'cashier.allow_promotion_codes' => true]);
    claimOfferFor($this->account);

    $subscription = configureCheckout($this->account);

    expect($subscription->couponId)->toBeNull()
        ->and(trialExpiresAt($subscription))->toBeNull()
        ->and($subscription->allowPromotionCodes)->toBeTrue();
});

test('clamps a one-day trial to the Stripe Checkout minimum of two days', function () {
    config(['cashier.trial_days' => 1]);

    expect(ConfigureSubscriptionCheckout::trialDaysFor($this->account))->toBe(ConfigureSubscriptionCheckout::MIN_CHECKOUT_TRIAL_DAYS);
});

test('zero trial days means no trial', function () {
    config(['cashier.trial_days' => 0]);

    expect(trialExpiresAt(configureCheckout($this->account)))->toBeNull();
});

test('an offer applies to a former customer', function () {
    givePriorSubscription($this->account);
    claimOfferFor($this->account);

    expect(configureCheckout($this->account)->couponId)->toBe('BF26_50');
});

test('a first-subscription offer applies to a first-time subscriber only', function () {
    claimOfferFor($this->account, ['stripe_coupon_id' => 'FIRST_MONTH_1', 'first_subscription_only' => true]);

    expect(configureCheckout($this->account)->couponId)->toBe('FIRST_MONTH_1');

    givePriorSubscription($this->account);
    $this->account->unsetRelation('subscriptions');

    expect(ConfigureSubscriptionCheckout::offerFor($this->account, socialsPlan()))->toBeNull();
});

test('does not apply an offer without a plan', function () {
    claimOfferFor($this->account);

    expect(ConfigureSubscriptionCheckout::offerFor($this->account, null))->toBeNull();
});

test('does not apply an expired offer', function () {
    claimOfferFor($this->account, ['starts_at' => now()->subDays(10), 'ends_at' => now()->subDay()]);

    expect(configureCheckout($this->account)->couponId)->toBeNull();
});
