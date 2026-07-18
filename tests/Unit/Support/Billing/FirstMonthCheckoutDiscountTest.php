<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Workspace;
use App\Support\Billing\FirstMonthCheckoutDiscount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\SubscriptionBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->account = Account::factory()->create();

    config([
        'trypost.billing.require_card_for_trial' => true,
        'cashier.first_month_coupon_id' => 'TRIAL1USD',
    ]);
});

function firstMonthSubscription(Account $account): SubscriptionBuilder
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

test('applies the first month coupon for a first-time single-workspace checkout', function () {
    Workspace::factory()->create(['account_id' => $this->account->id]);

    $subscription = firstMonthSubscription($this->account);

    FirstMonthCheckoutDiscount::apply($subscription, $this->account);

    expect($subscription->couponId)->toBe('TRIAL1USD')
        ->and($subscription->promotionCodeId)->toBeNull()
        ->and($subscription->allowPromotionCodes)->toBeFalse();
});

test('skips the coupon and allows promotion codes when a card is not required', function () {
    config(['trypost.billing.require_card_for_trial' => false]);
    Workspace::factory()->create(['account_id' => $this->account->id]);

    $subscription = firstMonthSubscription($this->account);

    FirstMonthCheckoutDiscount::apply($subscription, $this->account);

    expect($subscription->allowPromotionCodes)->toBeTrue()
        ->and($subscription->couponId)->toBeNull();
});

test('skips the coupon when more than one workspace is billed', function () {
    Workspace::factory()->count(2)->create(['account_id' => $this->account->id]);

    $subscription = firstMonthSubscription($this->account);

    FirstMonthCheckoutDiscount::apply($subscription, $this->account);

    expect($subscription->allowPromotionCodes)->toBeTrue()
        ->and($subscription->couponId)->toBeNull();
});

test('skips the coupon when the account has a prior canceled subscription', function () {
    Workspace::factory()->create(['account_id' => $this->account->id]);
    givePriorSubscription($this->account);

    $subscription = firstMonthSubscription($this->account);

    FirstMonthCheckoutDiscount::apply($subscription, $this->account);

    expect($subscription->allowPromotionCodes)->toBeTrue()
        ->and($subscription->couponId)->toBeNull();
});

test('still applies the coupon when the only prior subscription never left incomplete', function () {
    Workspace::factory()->create(['account_id' => $this->account->id]);
    givePriorSubscription($this->account, 'incomplete_expired');

    $subscription = firstMonthSubscription($this->account);

    FirstMonthCheckoutDiscount::apply($subscription, $this->account);

    expect($subscription->couponId)->toBe('TRIAL1USD')
        ->and($subscription->allowPromotionCodes)->toBeFalse();
});

test('throws instead of charging full price when a qualifying checkout has no coupon', function () {
    config(['cashier.first_month_coupon_id' => '']);
    Workspace::factory()->create(['account_id' => $this->account->id]);

    $subscription = firstMonthSubscription($this->account);

    expect(fn () => FirstMonthCheckoutDiscount::apply($subscription, $this->account))
        ->toThrow(RuntimeException::class);

    expect($subscription->couponId)->toBeNull();
});
