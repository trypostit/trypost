<?php

declare(strict_types=1);

use App\Actions\Account\CancelAccounts;
use App\Models\Account;
use App\Models\User;
use Laravel\Cashier\Subscription;

test('cancel accounts returns true when every account cancels', function () {
    $owner = User::factory()->create();

    expect(CancelAccounts::execute([$owner->account]))->toBeTrue();
});

test('cancel accounts stops on the first failure', function () {
    $first = User::factory()->create()->account;
    $second = User::factory()->create()->account;

    $first->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_fail_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $mockSubscription = Mockery::mock(Subscription::class);
    $mockSubscription->shouldReceive('ended')->andReturnFalse();
    $mockSubscription->shouldReceive('cancelNow')
        ->once()
        ->andThrow(new RuntimeException('stripe unavailable'));

    $mockFirst = Mockery::mock($first)->makePartial();
    $mockFirst->shouldReceive('subscription')
        ->with(Account::SUBSCRIPTION_NAME)
        ->andReturn($mockSubscription);

    $mockSecond = Mockery::mock($second)->makePartial();
    $mockSecond->shouldReceive('subscription')->never();

    expect(CancelAccounts::execute([$mockFirst, $mockSecond]))->toBeFalse();
});
