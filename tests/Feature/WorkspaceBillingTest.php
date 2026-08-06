<?php

declare(strict_types=1);

use App\Enums\Plan\Slug as PlanSlug;
use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    config(['trypost.self_hosted' => true]);

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('account has active subscription in self hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    expect($this->account->hasActiveSubscription())->toBeTrue();
});

test('account without subscription is not active in saas mode', function () {
    config(['trypost.self_hosted' => false]);

    expect($this->account->hasActiveSubscription())->toBeFalse();
});

test('account belongs to plan', function () {
    $plan = Plan::where('slug', PlanSlug::Workspace)->first();
    $this->account->update(['plan_id' => $plan->id]);

    expect($this->account->fresh()->plan->id)->toBe($plan->id);
});

test('ensure subscribed middleware passes in self hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->get(route('app.calendar'));

    $response->assertOk();
});

test('ensure subscribed middleware redirects without subscription in saas mode', function () {
    config(['trypost.self_hosted' => false]);

    $response = $this->actingAs($this->user)->get(route('app.calendar'));

    $response->assertRedirect(route('app.welcome.persona'));
});

test('billing page is accessible by account owner', function () {
    config(['trypost.self_hosted' => false]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $response = $this->actingAs($this->user)->get(route('app.billing.index'));

    $response->assertOk();
});

test('billing page is not accessible by non-owner member', function () {
    config(['trypost.self_hosted' => false]);

    $member = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $response = $this->actingAs($member)->get(route('app.billing.index'));

    $response->assertForbidden();
});

test('subscribe redirects to welcome', function () {
    config(['trypost.self_hosted' => false]);

    $response = $this->actingAs($this->user)->get(route('app.subscribe'));

    $response->assertRedirect(route('app.welcome.persona'));
});

test('stripe email returns account owner email', function () {
    expect($this->account->stripeEmail())->toBe($this->user->email);
});

test('stripe name returns account name', function () {
    expect($this->account->stripeName())->toBe($this->account->name);
});

test('creating an additional workspace redirects to billing without an active subscription', function () {
    config(['trypost.self_hosted' => false]);

    // The account already owns one workspace (from beforeEach) and has no subscription.
    $response = $this->actingAs($this->user)->get(route('app.workspaces.create'));

    $response->assertRedirect(route('app.billing.index'));
});

test('creating an additional workspace is allowed with an active subscription', function () {
    config(['trypost.self_hosted' => false]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $response = $this->actingAs($this->user->fresh())->get(route('app.workspaces.create'));

    $response->assertOk();
});

test('store creates an additional workspace with no count limit', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->post(route('app.workspaces.store'), [
        'name' => 'Second workspace',
    ]);

    $response->assertRedirect(route('app.accounts'));
    expect($this->account->workspaces()->count())->toBe(2);
});
