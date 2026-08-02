<?php

declare(strict_types=1);

use App\Actions\Workspace\CreateWorkspace;
use App\Actions\Workspace\DeleteWorkspace;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Cashier\Subscription;

test('syncWorkspaceQuantity does not touch Stripe in self-hosted mode', function () {
    config()->set('trypost.self_hosted', true);

    $subscription = mock(Subscription::class);
    $subscription->shouldReceive('active')->andReturnTrue();
    $subscription->shouldReceive('updateQuantity')->never();

    $account = mock(Account::class)->makePartial();
    $account->shouldReceive('subscription')->with(Account::SUBSCRIPTION_NAME)->andReturn($subscription);
    $account->shouldReceive('workspaces->count')->andReturn(3);

    $account->syncWorkspaceQuantity();
});

test('syncWorkspaceQuantity does not touch Stripe without an active subscription', function () {
    config()->set('trypost.self_hosted', false);

    $subscription = mock(Subscription::class);
    $subscription->shouldReceive('active')->andReturnFalse();
    $subscription->shouldReceive('updateQuantity')->never();

    $account = mock(Account::class)->makePartial();
    $account->shouldReceive('subscription')->with(Account::SUBSCRIPTION_NAME)->andReturn($subscription);

    $account->syncWorkspaceQuantity();
});

test('syncWorkspaceQuantity updates the subscription quantity to the workspace count', function () {
    config()->set('trypost.self_hosted', false);

    $subscription = mock(Subscription::class);
    $subscription->shouldReceive('active')->andReturnTrue();
    $subscription->shouldReceive('updateQuantity')->once()->with(3);

    $account = mock(Account::class)->makePartial();
    $account->shouldReceive('subscription')->with(Account::SUBSCRIPTION_NAME)->andReturn($subscription);
    $account->shouldReceive('workspaces->count')->andReturn(3);

    $account->syncWorkspaceQuantity();
});

test('creating a workspace syncs the stripe quantity', function () {
    $user = User::factory()->create();

    $account = mock(Account::class)->makePartial();
    $account->shouldReceive('syncWorkspaceQuantity')->once();
    $user->setRelation('account', $account);

    CreateWorkspace::execute($user, ['name' => 'Wiring']);
});

test('deleting a workspace syncs the stripe quantity', function () {
    config()->set('trypost.self_hosted', true);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    $account = Mockery::mock($user->account)->makePartial();
    $account->shouldReceive('syncWorkspaceQuantity')->once();
    $workspace->setRelation('account', $account);

    expect(DeleteWorkspace::execute($workspace))->toBeTrue();
});
