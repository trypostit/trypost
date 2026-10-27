<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Route;
use Stripe\ApiRequestor;

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
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
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

test('changePlan redirects to calendar in self hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)
        ->post(route('app.billing.change-plan'), [
            'plan_id' => Plan::where('slug', 'socials')->value('id'),
            'interval' => 'yearly',
        ]);

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
        ->missing('redirectToOnboarding')
    );
});

test('billing processing keeps auth.plan null until the webhook writes plan_id', function () {
    config(['trypost.self_hosted' => false]);

    $this->account->update(['plan_id' => null]);
    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('billing/Processing', false)
            ->where('subscriptionActive', true)
            ->where('auth.plan', null)
        );
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
    $this->workspace->members()->attach($admin->id, membershipPivot('admin'));
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
    $this->workspace->members()->attach($member->id, membershipPivot('member'));
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $this->actingAs($member)->get(route('app.billing.index'))->assertForbidden();
});

test('changePlan forbids a non-owner', function () {
    config(['trypost.self_hosted' => false]);

    $member = User::factory()->create(['account_id' => $this->account->id]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_monthly',
    ]);

    $this->actingAs($member)
        ->post(route('app.billing.change-plan'), [
            'plan_id' => Plan::where('slug', 'socials')->value('id'),
            'interval' => 'yearly',
        ])
        ->assertForbidden();
});

test('changePlan writes plan_id and flashes when already on that price', function () {
    config(['trypost.self_hosted' => false]);

    $plan = Plan::where('slug', 'socials')->first();
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
        ->post(route('app.billing.change-plan'), [
            'plan_id' => $plan->id,
            'interval' => 'yearly',
        ])
        ->assertRedirect(route('app.billing.index'))
        ->assertSessionHas('flash.success', __('billing.flash.plan_changed', [
            'plan' => $plan->name,
        ]));

    expect($this->account->fresh()->plan_id)->toBe($plan->id);
});

test('changePlan requires authentication', function () {
    $response = $this->post(route('app.billing.change-plan'));

    $response->assertRedirect(route('login'));
});

test('billing index exposes the plan and no account details', function (string $slug) {
    config(['trypost.self_hosted' => false]);

    $this->account->update([
        'plan_id' => Plan::where('slug', $slug)->value('id'),
    ]);
    subscribeAccount($this->account);

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/account/Billing', false)
            ->missing('account')
            ->where('plan.slug', $slug)
            ->where('workspaceCount', 1)
            ->missing('workspaceLimit')
            ->where('subscription.ends_at', null)
        );
})->with([
    'socials' => ['socials'],
    'workspaces' => ['workspaces'],
]);

test('billing index exposes the end date of a cancelled subscription', function () {
    config(['trypost.self_hosted' => false]);

    $endsAt = now()->addDays(10)->startOfSecond();
    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
        'ends_at' => $endsAt,
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('hasSubscription', true)
            ->where('onTrial', false)
            ->where('subscription.stripe_status', 'active')
            ->where('subscription.ends_at', $endsAt->toIso8601ZuluString('microsecond'))
        );
});

test('billing index exposes a past due subscription', function () {
    config(['trypost.self_hosted' => false]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_123',
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.index'))
        ->assertInertia(fn ($page) => $page
            ->where('subscription.stripe_status', 'past_due')
            ->where('subscription.ends_at', null)
        );
});

test('the account settings page no longer exists', function () {
    expect(Route::has('app.account.edit'))->toBeFalse()
        ->and(Route::has('app.account.update'))->toBeFalse();
});

test('billing index shows a percentage discount and when it ends', function () {
    config(['trypost.self_hosted' => false]);
    $endsAt = now()->addMonths(3)->startOfSecond();
    fakeStripeBilling($this->account, ['percent_off' => 50, 'amount_off' => null, 'currency' => null, 'duration' => 'repeating', 'duration_in_months' => 3], $endsAt->timestamp);

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('discount.value', '50%')
            ->where('discount.duration', 'repeating')
            ->where('discount.ends_at', $endsAt->toIso8601String())
        );
});

test('billing index shows an amount discount that never ends', function () {
    config(['trypost.self_hosted' => false]);
    fakeStripeBilling($this->account, ['percent_off' => null, 'amount_off' => 1800, 'currency' => 'usd', 'duration' => 'forever', 'duration_in_months' => null]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('discount.value', '$18.00')
            ->where('discount.duration', 'forever')
            ->where('discount.ends_at', null)
        );
});

test('billing index sends no discount without one', function () {
    config(['trypost.self_hosted' => false]);
    fakeStripeBilling($this->account);

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('discount', null));
});

test('billing index does not ask Stripe for a discount without a Stripe customer', function () {
    config(['trypost.self_hosted' => false]);
    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_no_customer',
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('discount', null));
});

test('billing index sends the card brand Stripe reports', function () {
    config(['trypost.self_hosted' => false]);
    fakeStripeBilling($this->account, cardBrand: 'mastercard');

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('paymentMethods', 1)
            ->where('paymentMethods.0.brand', 'mastercard')
            ->where('paymentMethods.0.last4', '4242')
            ->where('paymentMethods.0.is_default', true)
        );
});

test('billing index lists every card, the default one first', function () {
    config(['trypost.self_hosted' => false]);
    fakeStripeBilling($this->account, cardBrand: 'visa', secondCardBrand: 'mastercard');

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('paymentMethods', 2)
            ->where('paymentMethods.0.id', 'pm_fake')
            ->where('paymentMethods.0.brand', 'visa')
            ->where('paymentMethods.0.is_default', true)
            ->where('paymentMethods.1.id', 'pm_fake_second')
            ->where('paymentMethods.1.brand', 'mastercard')
            ->where('paymentMethods.1.last4', '5454')
            ->where('paymentMethods.1.exp_year', 2055)
            ->where('paymentMethods.1.is_default', false)
        );
});

test('billing index takes the first card as the default when the customer has none', function () {
    config(['trypost.self_hosted' => false]);
    fakeStripeBilling($this->account, cardBrand: 'visa', secondCardBrand: 'mastercard', customerHasDefault: false);

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paymentMethods.0.id', 'pm_fake_second')
            ->where('paymentMethods.0.is_default', true)
            ->where('paymentMethods.1.is_default', false)
        );
});

test('billing index lists no cards without any', function () {
    config(['trypost.self_hosted' => false]);
    fakeStripeBilling($this->account);

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('paymentMethods', []));
});

test('billing index lists the paid invoices a page at a time, newest first', function () {
    config(['trypost.self_hosted' => false, 'app.pagination.default' => 10]);
    fakeStripeBilling($this->account, paidInvoices: 12);

    $nextPage = null;

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertInertia(function ($page) use (&$nextPage) {
            $page->has('invoices.data', 10)
                ->where('invoices.data.0.id', 'in_fake_012')
                ->where('invoices.data.0.total', '$19.00')
                ->where('invoices.data.0.invoice_pdf', 'https://pay.stripe.com/invoice/in_fake_12/pdf')
                ->where('invoices.data.9.id', 'in_fake_003')
                ->whereType('invoices.meta.next_cursor', 'string');

            $nextPage = $page->toArray()['props']['invoices']['meta']['next_cursor'];
        });

    $this->get(route('app.billing.index', ['cursor' => $nextPage]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('invoices.data', 2)
            ->where('invoices.data.0.id', 'in_fake_002')
            ->where('invoices.data.1.id', 'in_fake_001')
            ->where('invoices.meta.next_cursor', null)
        );
});

test('billing index lists no invoices for an account without any', function () {
    config(['trypost.self_hosted' => false]);
    fakeStripeBilling($this->account);

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('invoices.data', 0)
            ->where('invoices.meta.next_cursor', null)
        );
});
