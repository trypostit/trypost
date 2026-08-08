<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Workspace;
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
        'cashier.first_month_coupon_id' => '',
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
    $property = (new \ReflectionClass($subscription))->getProperty('trialExpires');

    return $property->getValue($subscription);
}

test('recipe A applies an eight-day trial without coupon or promotion codes', function () {
    Workspace::factory()->create(['account_id' => $this->account->id]);

    Carbon::setTestNow('2026-08-07 12:00:00');

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect($subscription->couponId)->toBeNull()
        ->and($subscription->allowPromotionCodes)->toBeFalse()
        ->and(trialExpiresAt($subscription)?->toDateTimeString())->toBe('2026-08-15 12:00:00');
});

test('recipe B applies the first-month coupon and skips trial days', function () {
    config([
        'cashier.first_month_coupon_id' => 'TRIAL1USD',
        'cashier.allow_promotion_codes' => false,
        'cashier.trial_days' => 8,
    ]);
    Workspace::factory()->create(['account_id' => $this->account->id]);

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect($subscription->couponId)->toBe('TRIAL1USD')
        ->and($subscription->allowPromotionCodes)->toBeFalse()
        ->and(trialExpiresAt($subscription))->toBeNull();
});

test('recipe C applies trial days and allows promotion codes', function () {
    config(['cashier.allow_promotion_codes' => true]);
    Workspace::factory()->create(['account_id' => $this->account->id]);

    Carbon::setTestNow('2026-08-07 12:00:00');

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect($subscription->couponId)->toBeNull()
        ->and($subscription->allowPromotionCodes)->toBeTrue()
        ->and(trialExpiresAt($subscription)?->toDateTimeString())->toBe('2026-08-15 12:00:00');
});

test('throws when a qualifying coupon would combine with allow promotion codes', function () {
    config([
        'cashier.first_month_coupon_id' => 'TRIAL1USD',
        'cashier.allow_promotion_codes' => true,
    ]);
    Workspace::factory()->create(['account_id' => $this->account->id]);

    $subscription = checkoutSubscription($this->account);

    expect(fn () => ConfigureSubscriptionCheckout::apply($subscription, $this->account))
        ->toThrow(RuntimeException::class);

    expect($subscription->couponId)->toBeNull()
        ->and(trialExpiresAt($subscription))->toBeNull();
});

test('empty coupon with card required does not throw and still applies trial', function () {
    config([
        'cashier.first_month_coupon_id' => '',
        'cashier.allow_promotion_codes' => false,
    ]);
    Workspace::factory()->create(['account_id' => $this->account->id]);

    Carbon::setTestNow('2026-08-07 12:00:00');

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect($subscription->couponId)->toBeNull()
        ->and(trialExpiresAt($subscription)?->toDateTimeString())->toBe('2026-08-15 12:00:00');
});

test('skips the coupon and allows promotion codes when a card is not required', function () {
    config([
        'trypost.billing.require_card_for_trial' => false,
        'cashier.first_month_coupon_id' => 'TRIAL1USD',
        'cashier.allow_promotion_codes' => true,
    ]);
    Workspace::factory()->create(['account_id' => $this->account->id]);

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect($subscription->allowPromotionCodes)->toBeTrue()
        ->and($subscription->couponId)->toBeNull()
        ->and(trialExpiresAt($subscription))->toBeNull();
});

test('skips the coupon when more than one workspace is billed and still applies trial', function () {
    config(['cashier.first_month_coupon_id' => 'TRIAL1USD']);
    Workspace::factory()->count(2)->create(['account_id' => $this->account->id]);

    Carbon::setTestNow('2026-08-07 12:00:00');

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect($subscription->couponId)->toBeNull()
        ->and(trialExpiresAt($subscription)?->toDateTimeString())->toBe('2026-08-15 12:00:00');
});

test('skips the coupon when the account has a prior canceled subscription', function () {
    config(['cashier.first_month_coupon_id' => 'TRIAL1USD']);
    Workspace::factory()->create(['account_id' => $this->account->id]);
    givePriorSubscription($this->account);

    Carbon::setTestNow('2026-08-07 12:00:00');

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect($subscription->couponId)->toBeNull()
        ->and(trialExpiresAt($subscription)?->toDateTimeString())->toBe('2026-08-15 12:00:00');
});

test('still applies the coupon when the only prior subscription never left incomplete', function () {
    config([
        'cashier.first_month_coupon_id' => 'TRIAL1USD',
        'cashier.allow_promotion_codes' => false,
    ]);
    Workspace::factory()->create(['account_id' => $this->account->id]);
    givePriorSubscription($this->account, 'incomplete_expired');

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect($subscription->couponId)->toBe('TRIAL1USD')
        ->and($subscription->allowPromotionCodes)->toBeFalse()
        ->and(trialExpiresAt($subscription))->toBeNull();
});

test('clamps a one-day trial to Stripe Checkout minimum of two days', function () {
    config(['cashier.trial_days' => 1]);
    Workspace::factory()->create(['account_id' => $this->account->id]);

    Carbon::setTestNow('2026-08-07 12:00:00');

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect(trialExpiresAt($subscription)?->toDateTimeString())->toBe('2026-08-09 12:00:00');
});

test('skips trial days when trial_days is zero', function () {
    config(['cashier.trial_days' => 0]);
    Workspace::factory()->create(['account_id' => $this->account->id]);

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect(trialExpiresAt($subscription))->toBeNull()
        ->and($subscription->couponId)->toBeNull()
        ->and($subscription->allowPromotionCodes)->toBeFalse();
});
