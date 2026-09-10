<?php

declare(strict_types=1);

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Enums\Plan\Slug;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Enums\User\Goal;
use App\Enums\User\Locale;
use App\Enums\User\Persona;
use App\Enums\User\ReferralSource;
use App\Enums\UserWorkspace\Role;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create([
        'persona' => Persona::Creator,
        'goals' => [Goal::SaveTime->value],
        'referral_source' => ReferralSource::Google,
    ]);

    $workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $workspace->id]);

    SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::X,
        'status' => Status::Connected,
    ]);
});

test('connect store sends the user to the plan step instead of Stripe', function () {
    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.connect.store'))
        ->assertRedirect(route('app.welcome.plan'));
});

test('the first-month offer copy mirrors the per-month suffix', function () {
    expect(__('billing.plans.per_first_month'))
        ->toBe('/first month')
        ->and(__('billing.plans.per_month'))->toBe('/month')
        ->and(__('billing.plans.then_monthly', ['price' => '$19']))->toBe('Then $19/month')
        ->and(__('billing.subscribe.prices.first_month'))->toBe('$1');
});

test('the plan step lists only active plans, ordered by sort', function () {
    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.plan'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome/Plan', false)
            ->has('plans', 2)
            ->where('plans.0.slug', Slug::Socials->value)
            ->where('plans.0.workspace_limit', 1)
            ->where('plans.1.slug', Slug::Workspaces->value)
            ->where('plans.1.workspace_limit', null)
        );
});

test('the welcome plan step stores a locale change and stays on the page', function () {
    $this->actingAs($this->user->fresh())
        ->from(route('app.welcome.plan'))
        ->put(route('app.profile.language'), ['locale' => 'pt-BR'])
        ->assertRedirect(route('app.welcome.plan'))
        ->assertCookieMissing('locale');

    expect($this->user->fresh()->locale)->toBe(Locale::PortugueseBrazil);
});

test('the plan step redirects back to connect when no account is connected', function () {
    $this->user->fresh()->currentWorkspace->socialAccounts()->delete();

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.plan'))
        ->assertRedirect(route('app.welcome.connect'));
});

test('the plan step rejects an archived plan', function () {
    $legacy = Plan::where('slug', Slug::Workspace)->firstOrFail();

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.plan.store'), [
            'plan_id' => $legacy->id,
            'interval' => 'monthly',
        ])
        ->assertSessionHasErrors('plan_id');
});

test('the plan step always checks out the monthly price of the chosen plan', function () {
    $plan = Plan::where('slug', Slug::Workspaces)->firstOrFail();
    $plan->update([
        'stripe_monthly_price_id' => 'price_workspaces_monthly',
        'stripe_yearly_price_id' => 'price_workspaces_yearly',
    ]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->withArgs(fn ($account, $priceId, $cancelUrl, $passedPlan) => $priceId === 'price_workspaces_monthly' && $passedPlan->is($plan))
        ->andReturn(redirect('https://checkout.stripe.test/session'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.plan.store'), [
            'plan_id' => $plan->id,
            'interval' => 'yearly',
        ])
        ->assertRedirect('https://checkout.stripe.test/session');

    expect($this->user->account->fresh()->plan_id)->toBeNull();
});

test('the plan step fails loudly when the price is not configured', function () {
    $plan = Plan::where('slug', Slug::Socials)->firstOrFail();
    $plan->update(['stripe_monthly_price_id' => null]);

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.plan.store'), [
            'plan_id' => $plan->id,
            'interval' => 'monthly',
        ])
        ->assertStatus(500);
});
