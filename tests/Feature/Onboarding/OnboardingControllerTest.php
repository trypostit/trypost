<?php

declare(strict_types=1);

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Enums\Plan\Slug;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Goal;
use App\Enums\User\Persona;
use App\Jobs\PostHog\SendEvent;
use App\Models\Account;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);
    $this->user = User::factory()->create();
});

/**
 * Give the acting user a current workspace under their account.
 */
function onboardingWorkspace(User $user): Workspace
{
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);

    $user->update(['current_workspace_id' => $workspace->id]);

    return $workspace;
}

function subscribeOnboardingAccount(Account $account): void
{
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);
}

test('onboarding renders the persona selection for an unsubscribed account', function () {
    $response = $this->actingAs($this->user)->get(route('app.onboarding'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('onboarding/Index')
        ->has('personas', count(Persona::cases()))
    );
});

test('onboarding redirects to calendar in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->get(route('app.onboarding'));

    $response->assertRedirect(route('app.calendar'));
});

test('onboarding redirects to calendar when already subscribed', function () {
    subscribeOnboardingAccount($this->user->account);

    $response = $this->actingAs($this->user->fresh())->get(route('app.onboarding'));

    $response->assertRedirect(route('app.calendar'));
});

test('onboarding store rejects an invalid persona', function () {
    $response = $this->actingAs($this->user)->post(route('app.onboarding.store'), [
        'persona' => 'not-a-persona',
    ]);

    $response->assertSessionHasErrors('persona');
    expect($this->user->fresh()->persona)->toBeNull();
});

test('onboarding store requires a persona', function () {
    $response = $this->actingAs($this->user)->post(route('app.onboarding.store'), []);

    $response->assertSessionHasErrors('persona');
});

test('onboarding store does nothing in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->post(route('app.onboarding.store'), [
        'persona' => Persona::Agency->value,
    ]);

    $response->assertRedirect(route('app.calendar'));
    expect($this->user->fresh()->persona)->toBeNull();
});

test('onboarding store saves the persona, mirrors to PostHog and advances to the goals step', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();

    $response = $this->actingAs($this->user)->post(route('app.onboarding.store'), [
        'persona' => Persona::Agency->value,
    ]);

    $response->assertRedirect(route('app.onboarding.goals'));
    expect($this->user->fresh()->persona)->toBe(Persona::Agency);

    Bus::assertDispatched(SendEvent::class);
});

test('onboarding store redirects an already-subscribed account to the calendar', function () {
    subscribeOnboardingAccount($this->user->account);

    $response = $this->actingAs($this->user->fresh())->post(route('app.onboarding.store'), [
        'persona' => Persona::Agency->value,
    ]);

    $response->assertRedirect(route('app.calendar'));
    expect($this->user->fresh()->persona)->toBeNull();
});

test('goals renders the goal selection for an account that picked a persona', function () {
    $this->user->update(['persona' => Persona::Agency->value]);

    $response = $this->actingAs($this->user->fresh())->get(route('app.onboarding.goals'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('onboarding/Goals')
        ->has('goals', count(Goal::cases()))
    );
});

test('goals redirects to the persona step when no persona was chosen', function () {
    $response = $this->actingAs($this->user)->get(route('app.onboarding.goals'));

    $response->assertRedirect(route('app.onboarding'));
});

test('goals redirects to calendar in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->get(route('app.onboarding.goals'));

    $response->assertRedirect(route('app.calendar'));
});

test('goals redirects to calendar when already subscribed', function () {
    subscribeOnboardingAccount($this->user->account);

    $response = $this->actingAs($this->user->fresh())->get(route('app.onboarding.goals'));

    $response->assertRedirect(route('app.calendar'));
});

test('goals store requires at least one goal', function () {
    $this->user->update(['persona' => Persona::Agency->value]);

    $response = $this->actingAs($this->user->fresh())->post(route('app.onboarding.goals.store'), ['goals' => []]);

    $response->assertSessionHasErrors('goals');
    expect($this->user->fresh()->goals)->toBeNull();
});

test('goals store rejects an invalid goal', function () {
    $this->user->update(['persona' => Persona::Agency->value]);

    $response = $this->actingAs($this->user->fresh())->post(route('app.onboarding.goals.store'), [
        'goals' => ['not-a-goal'],
    ]);

    $response->assertSessionHasErrors('goals.0');
});

test('goals store accepts any combination of valid goals', function () {
    $this->user->update(['persona' => Persona::Agency->value]);

    $response = $this->actingAs($this->user->fresh())->post(route('app.onboarding.goals.store'), [
        'goals' => [Goal::JustExploring->value, Goal::SaveTime->value],
    ]);

    $response->assertRedirect(route('app.onboarding.connect'));
    expect($this->user->fresh()->goals)->toBe([Goal::JustExploring->value, Goal::SaveTime->value]);
});

test('goals store saves the goals, mirrors to PostHog and advances to the connect step', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();

    $this->user->update(['persona' => Persona::Agency->value]);

    $response = $this->actingAs($this->user->fresh())->post(route('app.onboarding.goals.store'), [
        'goals' => [Goal::SaveTime->value, Goal::AiContent->value],
    ]);

    $response->assertRedirect(route('app.onboarding.connect'));
    expect($this->user->fresh()->goals)->toBe([Goal::SaveTime->value, Goal::AiContent->value]);

    Bus::assertDispatched(SendEvent::class);
});

test('goals store saves just exploring on its own as a real signal', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();

    $this->user->update(['persona' => Persona::Agency->value]);

    $response = $this->actingAs($this->user->fresh())->post(route('app.onboarding.goals.store'), [
        'goals' => [Goal::JustExploring->value],
    ]);

    $response->assertRedirect(route('app.onboarding.connect'));
    expect($this->user->fresh()->goals)->toBe([Goal::JustExploring->value]);

    Bus::assertDispatched(SendEvent::class);
});

test('goals store redirects to the persona step when no persona was chosen', function () {
    $response = $this->actingAs($this->user)->post(route('app.onboarding.goals.store'), [
        'goals' => [Goal::SaveTime->value],
    ]);

    $response->assertRedirect(route('app.onboarding'));
    expect($this->user->fresh()->goals)->toBeNull();
});

test('goals store does nothing in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);
    $this->user->update(['persona' => Persona::Agency->value]);

    $response = $this->actingAs($this->user->fresh())->post(route('app.onboarding.goals.store'), [
        'goals' => [Goal::SaveTime->value],
    ]);

    $response->assertRedirect(route('app.calendar'));
    expect($this->user->fresh()->goals)->toBeNull();
});

test('connect redirects to the goals step when a persona was chosen but no goals', function () {
    $this->user->update(['persona' => Persona::Agency->value]);
    onboardingWorkspace($this->user);

    $response = $this->actingAs($this->user->fresh())->get(route('app.onboarding.connect'));

    $response->assertRedirect(route('app.onboarding.goals'));
});

test('connect renders the network grid for an unsubscribed account that picked a persona', function () {
    $this->user->update(['persona' => Persona::Agency->value, 'goals' => [Goal::SaveTime->value]]);
    onboardingWorkspace($this->user);

    $response = $this->actingAs($this->user->fresh())->get(route('app.onboarding.connect'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('onboarding/Connect')
        ->has('platforms')
        ->has('platforms.0.network')
        ->has('accounts')
    );
});

test('connect passes the workspace plan so the client can fire begin_checkout', function () {
    $this->user->update(['persona' => Persona::Agency->value, 'goals' => [Goal::SaveTime->value]]);
    onboardingWorkspace($this->user);

    $plan = Plan::where('slug', Slug::Workspace)->firstOrFail();

    $response = $this->actingAs($this->user->fresh())->get(route('app.onboarding.connect'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('onboarding/Connect')
        ->where('plan.name', $plan->name)
        ->where('plan.interval', 'monthly')
    );
});

test('connect offers a single linkedin card and no standalone linkedin page card', function () {
    $this->user->update(['persona' => Persona::Agency->value, 'goals' => [Goal::SaveTime->value]]);
    onboardingWorkspace($this->user);

    $response = $this->actingAs($this->user->fresh())->get(route('app.onboarding.connect'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('onboarding/Connect')
        ->where('platforms', fn ($platforms) => collect($platforms)->contains('value', Platform::LinkedIn->value)
            && ! collect($platforms)->contains('value', Platform::LinkedInPage->value)
        )
    );
});

test('connect lists the workspace social accounts already connected', function () {
    $this->user->update(['persona' => Persona::Agency->value, 'goals' => [Goal::SaveTime->value]]);
    $workspace = onboardingWorkspace($this->user);
    SocialAccount::factory()->create(['workspace_id' => $workspace->id]);

    $response = $this->actingAs($this->user->fresh())->get(route('app.onboarding.connect'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('onboarding/Connect')
        ->has('accounts', 1)
    );
});

test('connect redirects back to the persona step when no persona was chosen', function () {
    onboardingWorkspace($this->user);

    $response = $this->actingAs($this->user->fresh())->get(route('app.onboarding.connect'));

    $response->assertRedirect(route('app.onboarding'));
});

test('connect redirects to calendar in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->get(route('app.onboarding.connect'));

    $response->assertRedirect(route('app.calendar'));
});

test('connect redirects to calendar when already subscribed', function () {
    subscribeOnboardingAccount($this->user->account);

    $response = $this->actingAs($this->user->fresh())->get(route('app.onboarding.connect'));

    $response->assertRedirect(route('app.calendar'));
});

test('checkout blocks and redirects back when no network is connected', function () {
    $this->user->update(['persona' => Persona::Agency->value, 'goals' => [Goal::SaveTime->value]]);
    onboardingWorkspace($this->user);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->never();

    $response = $this->actingAs($this->user->fresh())->post(route('app.onboarding.checkout'));

    $response->assertRedirect(route('app.onboarding.connect'));
});

test('checkout starts monthly checkout once at least one network is connected', function () {
    $this->user->update(['persona' => Persona::Agency->value, 'goals' => [Goal::SaveTime->value]]);
    $workspace = onboardingWorkspace($this->user);
    SocialAccount::factory()->create(['workspace_id' => $workspace->id]);

    Plan::where('slug', Slug::Workspace)->firstOrFail()->update([
        'stripe_monthly_price_id' => 'price_monthly_test',
        'stripe_yearly_price_id' => 'price_yearly_test',
    ]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->withArgs(fn (Account $account, string $priceId, string $cancelUrl): bool => $priceId === 'price_monthly_test')
        ->andReturn(redirect()->route('app.calendar'));

    $response = $this->actingAs($this->user->fresh())->post(route('app.onboarding.checkout'));

    $response->assertRedirect(route('app.calendar'));
});

test('checkout redirects an already-subscribed account to the calendar', function () {
    subscribeOnboardingAccount($this->user->account);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->never();

    $response = $this->actingAs($this->user->fresh())->post(route('app.onboarding.checkout'));

    $response->assertRedirect(route('app.calendar'));
});

test('checkout does nothing in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->never();

    $response = $this->actingAs($this->user)->post(route('app.onboarding.checkout'));

    $response->assertRedirect(route('app.calendar'));
});

test('connect redirects to workspace creation when no workspace exists', function () {
    $this->user->update(['persona' => Persona::Agency->value, 'goals' => [Goal::SaveTime->value]]);

    $response = $this->actingAs($this->user->fresh())->get(route('app.onboarding.connect'));

    $response->assertRedirect(route('app.workspaces.create'));
});

test('a user walks the full onboarding flow from the account gate to stripe checkout', function () {
    Plan::where('slug', Slug::Workspace)->firstOrFail()->update(['stripe_monthly_price_id' => 'price_monthly_test']);
    $workspace = onboardingWorkspace($this->user);

    // The account gate sends an unsubscribed user into onboarding.
    $this->actingAs($this->user->fresh())->get(route('app.calendar'))
        ->assertRedirect(route('app.onboarding'));

    // Persona advances to goals.
    $this->actingAs($this->user->fresh())->post(route('app.onboarding.store'), ['persona' => Persona::Creator->value])
        ->assertRedirect(route('app.onboarding.goals'));

    // Goals advances to connect.
    $this->actingAs($this->user->fresh())->post(route('app.onboarding.goals.store'), [
        'goals' => [Goal::AiContent->value, Goal::SaveTime->value],
    ])->assertRedirect(route('app.onboarding.connect'));

    // Connect renders now that persona, goals and a workspace are present.
    $this->actingAs($this->user->fresh())->get(route('app.onboarding.connect'))->assertOk();

    // Checkout blocks until a network is connected.
    $this->actingAs($this->user->fresh())->post(route('app.onboarding.checkout'))
        ->assertRedirect(route('app.onboarding.connect'));

    // Once a network is connected, checkout hands off to Stripe.
    SocialAccount::factory()->create(['workspace_id' => $workspace->id]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->andReturn(redirect('https://checkout.stripe.test/session'));

    $this->actingAs($this->user->fresh())->post(route('app.onboarding.checkout'))
        ->assertRedirect('https://checkout.stripe.test/session');

    expect($this->user->fresh()->persona)->toBe(Persona::Creator);
    expect($this->user->fresh()->goals)->toBe([Goal::AiContent->value, Goal::SaveTime->value]);
});

test('a self-hosted user never enters the onboarding flow', function () {
    config(['trypost.self_hosted' => true]);
    onboardingWorkspace($this->user);

    // The app is reachable directly, with no subscription.
    $this->actingAs($this->user->fresh())->get(route('app.calendar'))->assertOk();

    // Every onboarding step just bounces to the calendar.
    foreach (['app.onboarding', 'app.onboarding.goals', 'app.onboarding.connect'] as $routeName) {
        $this->actingAs($this->user->fresh())->get(route($routeName))->assertRedirect(route('app.calendar'));
    }
});
