<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    config()->set('trypost.self_hosted', false);
});

test('redirects owners to welcome when account has no active subscription', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);

    $this->actingAs($user)
        ->get(route('app.calendar'))
        ->assertRedirect(route('app.welcome.persona'));
});

test('redirects members without app access straight to subscription required', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['account_id' => $owner->account_id]);
    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($member->fresh())
        ->get(route('app.calendar'))
        ->assertRedirect(route('app.welcome.subscription-required'));
});

test('redirects to workspace create when subscribed but no workspace', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $this->actingAs($user)
        ->get(route('app.calendar'))
        ->assertRedirect(route('app.workspaces.create'));
});

test('passes through when subscribed and has workspace', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);

    $workspace = Workspace::factory()->create(['account_id' => $account->id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $this->actingAs($user)
        ->get(route('app.calendar'))
        ->assertOk();
});

test('skips subscription check when self-hosted is enabled', function () {
    config()->set('trypost.self_hosted', true);

    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);

    $workspace = Workspace::factory()->create(['account_id' => $account->id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->get(route('app.calendar'))
        ->assertOk();
});

test('redirects to workspace create when self-hosted and no workspace', function () {
    config()->set('trypost.self_hosted', true);

    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);

    $this->actingAs($user)
        ->get(route('app.calendar'))
        ->assertRedirect(route('app.workspaces.create'));
});
