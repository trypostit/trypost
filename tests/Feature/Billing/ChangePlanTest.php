<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->socials = Plan::where('slug', Slug::Socials)->firstOrFail();
    $this->socials->update([
        'stripe_monthly_price_id' => 'price_socials_monthly',
        'stripe_yearly_price_id' => 'price_socials_yearly',
    ]);

    $this->workspaces = Plan::where('slug', Slug::Workspaces)->firstOrFail();
    $this->workspaces->update([
        'stripe_monthly_price_id' => 'price_workspaces_monthly',
        'stripe_yearly_price_id' => 'price_workspaces_yearly',
    ]);
});

$withWorkspace = function (User $user): Workspace {
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    return $workspace;
};

test('a non-owner cannot change the plan', function () use ($withWorkspace) {
    $owner = User::factory()->create();
    $workspace = $withWorkspace($owner);
    subscribeAccount($owner->account);

    $member = User::factory()->create(['account_id' => $owner->account_id]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($member->fresh())
        ->post(route('app.billing.change-plan'), [
            'plan_id' => $this->workspaces->id,
            'interval' => 'monthly',
        ])
        ->assertForbidden();
});

test('an archived plan is rejected', function () use ($withWorkspace) {
    $user = User::factory()->create();
    $withWorkspace($user);
    subscribeAccount($user->account);
    $legacy = Plan::where('slug', Slug::Workspace)->firstOrFail();

    $this->actingAs($user->fresh())
        ->post(route('app.billing.change-plan'), [
            'plan_id' => $legacy->id,
            'interval' => 'monthly',
        ])
        ->assertSessionHasErrors('plan_id');
});

test('downgrading is denied while the account holds more workspaces than the target allows', function () {
    $user = User::factory()->create();
    $account = $user->account;
    $account->update(['plan_id' => $this->workspaces->id]);

    Workspace::factory()->count(2)->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
    ]);

    subscribeAccount($account);

    $response = Gate::forUser($user)->inspect('swapPlan', [$account->fresh(), $this->socials]);

    expect($response->denied())->toBeTrue()
        ->and($response->message())->toBe(__('billing.flash.too_many_workspaces', [
            'count' => 2,
            'limit' => 1,
        ]));
});

test('downgrading is allowed once the account is inside the target limit', function () {
    $user = User::factory()->create();
    $account = $user->account;
    $account->update(['plan_id' => $this->workspaces->id]);

    subscribeAccount($account);

    $response = Gate::forUser($user)->inspect('swapPlan', [$account->fresh(), $this->socials]);

    expect($response->allowed())->toBeTrue();
});

test('change-plan flashes when the account has too many workspaces for the target', function () use ($withWorkspace) {
    $user = User::factory()->create();
    $account = $user->account;
    $account->update(['plan_id' => $this->workspaces->id]);

    $withWorkspace($user);
    Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
    ]);

    subscribeAccount($account);

    $this->actingAs($user->fresh())
        ->from(route('app.billing.index'))
        ->post(route('app.billing.change-plan'), [
            'plan_id' => $this->socials->id,
            'interval' => 'monthly',
        ])
        ->assertRedirect(route('app.billing.index'))
        ->assertSessionHas('flash.error', __('billing.flash.too_many_workspaces', [
            'count' => 2,
            'limit' => 1,
        ]));
});

test('change-plan is a no-op when the subscription is already on that price', function () use ($withWorkspace) {
    $user = User::factory()->create();
    $account = $user->account;
    $account->update(['plan_id' => $this->socials->id]);
    $withWorkspace($user);

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_socials_yearly',
    ]);

    $this->actingAs($user->fresh())
        ->post(route('app.billing.change-plan'), [
            'plan_id' => $this->socials->id,
            'interval' => 'yearly',
        ])
        ->assertRedirect(route('app.billing.index'))
        ->assertSessionMissing('flash.success');
});
