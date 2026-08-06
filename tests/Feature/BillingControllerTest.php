<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    config(['trypost.billing.require_card_for_trial' => true]);

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

// Subscribe tests
test('subscribe requires authentication', function () {
    $response = $this->get(route('app.subscribe'));

    $response->assertRedirect(route('login'));
});

test('subscribe redirects to welcome', function () {
    config(['trypost.self_hosted' => false]);

    $response = $this->actingAs($this->user)->get(route('app.subscribe'));

    $response->assertRedirect(route('app.welcome.persona'));
});

test('swapToYearly redirects to calendar in self hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)
        ->post(route('app.billing.swap-to-yearly'));

    $response->assertRedirect(route('app.calendar'));
});

test('portal redirects to calendar in self hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->get(route('app.billing.portal'));

    $response->assertRedirect(route('app.calendar'));
});

// Index tests
test('billing index requires authentication', function () {
    $response = $this->get(route('app.billing.index'));

    $response->assertRedirect(route('login'));
});

test('billing index shows billing dashboard', function () {
    config(['trypost.self_hosted' => false]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $response = $this->actingAs($this->user)->get(route('app.billing.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/account/Billing', false)
        ->has('hasSubscription')
        ->has('plan')
        ->has('workspaceCount')
    );
});

test('billing index exposes onTrial=true and trialEndsAt for subscription-trial account', function () {
    config(['trypost.self_hosted' => false]);

    $subscriptionEndsAt = now()->addDays(5)->startOfSecond();
    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_123',
        'trial_ends_at' => $subscriptionEndsAt,
    ]);

    $response = $this->actingAs($this->user->fresh())->get(route('app.billing.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('hasSubscription', true)
        ->where('onTrial', true)
        ->where('trialEndsAt', $subscriptionEndsAt->toIso8601ZuluString('microsecond'))
    );
});

test('billing index exposes onTrial=false and trialEndsAt=null for paying subscribed user', function () {
    config(['trypost.self_hosted' => false]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $response = $this->actingAs($this->user->fresh())->get(route('app.billing.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('hasSubscription', true)
        ->where('onTrial', false)
        ->where('trialEndsAt', null)
    );
});

test('billing index redirects to calendar in self hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->get(route('app.billing.index'));

    $response->assertRedirect(route('app.calendar'));
});

// Processing tests
test('billing processing requires authentication', function () {
    $response = $this->get(route('app.billing.processing'));

    $response->assertRedirect(route('login'));
});

test('billing processing shows processing page', function () {
    config(['trypost.self_hosted' => false]);

    $response = $this->actingAs($this->user)->get(route('app.billing.processing'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('billing/Processing', false)
        ->has('subscriptionActive')
        ->where('fromCheckout', false)
        ->where('conversion', null)
    );
});

test('billing processing exposes fromCheckout=true only the first time a session_id is seen', function () {
    config(['trypost.self_hosted' => false]);

    $sessionId = 'cs_test_'.fake()->uuid();

    $first = $this->actingAs($this->user)
        ->get(route('app.billing.processing', ['session_id' => $sessionId]));
    $first->assertOk();
    $first->assertInertia(fn ($page) => $page->where('fromCheckout', true));

    // A back-button / refresh to the same success URL must not re-fire the event.
    $second = $this->actingAs($this->user)
        ->get(route('app.billing.processing', ['session_id' => $sessionId]));
    $second->assertOk();
    $second->assertInertia(fn ($page) => $page->where('fromCheckout', false));
});

test('billing processing exposes null conversion when session_id query param is missing', function () {
    config(['trypost.self_hosted' => false]);

    $response = $this->actingAs($this->user)
        ->get(route('app.billing.processing', ['session_id' => '']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('conversion', null));
});

test('billing processing exposes null conversion when account has no stripe_id', function () {
    config(['trypost.self_hosted' => false]);

    expect($this->account->stripe_id)->toBeNull();

    $response = $this->actingAs($this->user)
        ->get(route('app.billing.processing', ['session_id' => 'cs_test_123']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('conversion', null));
});

test('shared auth.plan exposes name slug and interval via AuthPlanResource', function () {
    config(['trypost.self_hosted' => false]);

    $plan = Plan::where('slug', 'workspace')->firstOrFail();
    $this->account->update(['plan_id' => $plan->id]);

    $response = $this->actingAs($this->user->fresh())->get(route('app.billing.processing'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('auth.plan.name', 'Workspace')
        ->where('auth.plan.slug', 'workspace')
        ->where('auth.plan.interval', 'monthly')
    );
});

test('billing processing redirects to calendar in self hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->get(route('app.billing.processing'));

    $response->assertRedirect(route('app.calendar'));
});

// Checkout tests
// Portal tests
test('portal requires authentication', function () {
    $response = $this->get(route('app.billing.portal'));

    $response->assertRedirect(route('login'));
});

// Authorization tests
test('non-owner admin cannot access billing index', function () {
    config(['trypost.self_hosted' => false]);

    $admin = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->workspace->members()->attach($admin->id, ['role' => Role::Admin->value]);
    $admin->update(['current_workspace_id' => $this->workspace->id]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $this->actingAs($admin)->get(route('app.billing.index'))->assertForbidden();
});

test('member cannot access billing index', function () {
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

    $this->actingAs($member)->get(route('app.billing.index'))->assertForbidden();
});

// Swap-to-yearly tests
test('swapToYearly forbids a non-owner', function () {
    config(['trypost.self_hosted' => false]);

    $member = User::factory()->create(['account_id' => $this->account->id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_monthly',
    ]);

    $this->actingAs($member)
        ->post(route('app.billing.swap-to-yearly'))
        ->assertForbidden();
});

test('swapToYearly is a no-op when already on annual billing', function () {
    config(['trypost.self_hosted' => false]);

    $plan = Plan::where('slug', 'workspace')->first();
    $plan->update([
        'stripe_monthly_price_id' => 'price_monthly',
        'stripe_yearly_price_id' => 'price_yearly',
    ]);
    $this->account->update(['plan_id' => $plan->id]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_yearly',
    ]);
    $this->user->unsetRelation('account');

    $this->actingAs($this->user)
        ->post(route('app.billing.swap-to-yearly'))
        ->assertRedirect(route('app.billing.index'));
});

test('swapToYearly requires authentication', function () {
    $response = $this->post(route('app.billing.swap-to-yearly'));

    $response->assertRedirect(route('login'));
});
