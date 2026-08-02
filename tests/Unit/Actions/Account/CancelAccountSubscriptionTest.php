<?php

declare(strict_types=1);

use App\Actions\Account\CancelAccountSubscription;
use App\Models\Account;
use App\Models\User;
use Laravel\Cashier\Subscription;

test('cancel succeeds when there is no subscription', function () {
    $owner = User::factory()->create();

    expect(CancelAccountSubscription::execute($owner->account))->toBeTrue();
});

test('cancel returns false when stripe cancelNow fails', function () {
    $owner = User::factory()->create();
    $account = $owner->account;

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $mockSubscription = Mockery::mock(Subscription::class);
    $mockSubscription->shouldReceive('ended')->andReturnFalse();
    $mockSubscription->shouldReceive('cancelNow')
        ->once()
        ->andThrow(new RuntimeException('stripe unavailable'));

    $mockAccount = Mockery::mock($account)->makePartial();
    $mockAccount->shouldReceive('subscription')
        ->with(Account::SUBSCRIPTION_NAME)
        ->andReturn($mockSubscription);

    expect(CancelAccountSubscription::execute($mockAccount))->toBeFalse();
});

test('cancel skips ended subscriptions', function () {
    $owner = User::factory()->create();
    $account = $owner->account;

    $mockSubscription = Mockery::mock(Subscription::class);
    $mockSubscription->shouldReceive('ended')->andReturnTrue();
    $mockSubscription->shouldReceive('cancelNow')->never();

    $mockAccount = Mockery::mock($account)->makePartial();
    $mockAccount->shouldReceive('subscription')
        ->with(Account::SUBSCRIPTION_NAME)
        ->andReturn($mockSubscription);

    expect(CancelAccountSubscription::execute($mockAccount))->toBeTrue();
});
