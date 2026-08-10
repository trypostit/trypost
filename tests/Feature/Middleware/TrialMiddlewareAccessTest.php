<?php

declare(strict_types=1);

use App\Actions\User\CreateUser;
use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);
    config(['trypost.billing.require_card_for_trial' => true]);
    $this->seed(PlanSeeder::class);
});

test('user without subscription is redirected to subscribe', function () {
    $user = CreateUser::execute([
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => 'password123',
        'registration_ip' => '127.0.0.1',
    ]);

    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $response = $this->actingAs($user->fresh())->get(route('app.accounts'));

    $response->assertRedirect(route('app.welcome.persona'));
});

test('user with active subscription can access the app', function () {
    $user = CreateUser::execute([
        'name' => 'Alice',
        'email' => 'alice2@example.com',
        'password' => 'password123',
        'registration_ip' => '127.0.0.1',
    ]);

    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $user->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $response = $this->actingAs($user->fresh())->get(route('app.accounts'));

    $response->assertOk();
});

test('user on trialing subscription (legacy trial-with-card) can access the app', function () {
    $account = Account::factory()->create([
        'trial_ends_at' => null,
        'stripe_id' => 'cus_test_'.fake()->uuid(),
    ]);
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_123',
        'trial_ends_at' => now()->addDays(5),
    ]);

    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $response = $this->actingAs($user->fresh())->get(route('app.accounts'));

    $response->assertOk();
});

test('user with past_due subscription can access the app instead of being forced to subscribe', function () {
    $account = Account::factory()->create([
        'trial_ends_at' => null,
        'stripe_id' => 'cus_test_'.fake()->uuid(),
    ]);
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_123',
    ]);

    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $response = $this->actingAs($user->fresh())->get(route('app.accounts'));

    $response->assertOk();
    $response->assertSessionMissing('errors');
});

test('user on generic trial can access the app when card is not required', function () {
    config(['trypost.billing.require_card_for_trial' => false]);

    $user = CreateUser::execute([
        'name' => 'Alice',
        'email' => 'alice-generic@example.com',
        'password' => 'password123',
        'registration_ip' => '127.0.0.1',
    ]);

    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $response = $this->actingAs($user->fresh())->get(route('app.accounts'));

    $response->assertOk();
});
