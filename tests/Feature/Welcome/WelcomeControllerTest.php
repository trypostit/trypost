<?php

declare(strict_types=1);

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Enums\Plan\Slug;
use App\Enums\PostHog\CheckoutEvent;
use App\Enums\PostHog\WelcomeEvent;
use App\Enums\User\Goal;
use App\Enums\User\Locale;
use App\Enums\User\Persona;
use App\Enums\User\PreviousSoftware;
use App\Jobs\PostHog\SendEvent;
use App\Models\Account;
use App\Models\Offer;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);
    $this->user = User::factory()->create();
});

test('welcome redirects to the persona step', function () {
    $this->actingAs($this->user)
        ->get(route('app.welcome'))
        ->assertRedirect(route('app.welcome.persona'));
});

test('persona renders for an unsubscribed account', function () {
    $this->actingAs($this->user)
        ->get(route('app.welcome.persona'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome/Persona', false)
            ->has('personas', count(Persona::cases()))
        );
});

test('persona requires a valid selection', function (array $payload) {
    $this->actingAs($this->user)
        ->post(route('app.welcome.persona.store'), $payload)
        ->assertSessionHasErrors('persona');

    expect($this->user->fresh()->persona)->toBeNull();
})->with([
    'missing' => [[]],
    'invalid' => [['persona' => 'not-a-persona']],
]);

test('persona store saves the selection mirrors it to PostHog and advances to goals', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();

    $this->actingAs($this->user)
        ->post(route('app.welcome.persona.store'), ['persona' => Persona::Agency->value])
        ->assertRedirect(route('app.welcome.goals'));

    expect($this->user->fresh()->persona)->toBe(Persona::Agency);
    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'distinctId') === $this->user->id
        && data_get($event->payload, 'event') === WelcomeEvent::Persona->value
        && data_get($event->payload, 'properties.persona') === Persona::Agency->value);
});

test('goals redirects to persona until a persona is selected', function () {
    $this->actingAs($this->user)
        ->get(route('app.welcome.goals'))
        ->assertRedirect(route('app.welcome.persona'));
});

test('goals renders after a persona is selected', function () {
    $this->user->update(['persona' => Persona::Agency->value]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.goals'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome/Goals', false)
            ->has('goals', count(Goal::cases()))
        );
});

test('goals requires at least one valid goal', function (array $goals, string $error) {
    $this->user->update(['persona' => Persona::Agency->value]);

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.goals.store'), ['goals' => $goals])
        ->assertSessionHasErrors($error);

    expect($this->user->fresh()->goals)->toBeNull();
})->with([
    'empty' => [[], 'goals'],
    'invalid' => [['not-a-goal'], 'goals.0'],
]);

test('goals store saves choices mirrors them to PostHog and advances to software', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    $this->user->update(['persona' => Persona::Creator->value]);

    $goals = [Goal::UseMcp->value, Goal::SaveTime->value];

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.goals.store'), ['goals' => $goals])
        ->assertRedirect(route('app.welcome.software'));

    expect($this->user->fresh()->goals)->toBe($goals);
    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === WelcomeEvent::Goals->value
        && data_get($event->payload, 'properties.goals') === $goals);
});

test('software redirects to goals until a goal is selected', function () {
    $this->user->update(['persona' => Persona::Agency->value]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.software'))
        ->assertRedirect(route('app.welcome.goals'));
});

test('software renders after goals are selected', function () {
    completeWelcomeThroughGoals($this->user);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.software'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome/Software', false)
            ->has('software', count(PreviousSoftware::cases()))
            ->where('software.0', ['value' => 'buffer', 'name' => 'Buffer'])
            ->where('software.10', ['value' => 'none', 'name' => null])
            ->where('selected', null)
        );
});

test('software requires a valid selection', function (array $payload) {
    completeWelcomeThroughGoals($this->user);

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.software.store'), $payload)
        ->assertSessionHasErrors('previous_software');

    expect($this->user->fresh()->previous_software)->toBeNull();
})->with([
    'missing' => [[]],
    'invalid' => [['previous_software' => 'not-a-tool']],
]);

test('software store saves the selection, mirrors it to PostHog and advances to plan', function (PreviousSoftware $software) {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    completeWelcomeThroughGoals($this->user);
    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.software.store'), ['previous_software' => $software->value])
        ->assertRedirect(route('app.welcome.plan'));

    expect($this->user->fresh()->previous_software)->toBe($software);
    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'identify'
        && data_get($event->payload, 'properties.previous_software') === $software->value);
    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === WelcomeEvent::Software->value
        && data_get($event->payload, 'properties.previous_software') === $software->value);
})->with([
    'a competitor' => [PreviousSoftware::Metricool],
    'no tool yet' => [PreviousSoftware::None],
]);

test('plan redirects to software until a tool is selected', function () {
    completeWelcomeThroughGoals($this->user);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.plan'))
        ->assertRedirect(route('app.welcome.software'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.plan.store'))
        ->assertRedirect(route('app.welcome.software'));
});

test('plan shows the Socials plan', function () {
    completeWelcomeThroughSoftware($this->user);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.plan'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome/Plan', false)
            ->where('plan.slug', Slug::Socials->value)
            ->where('plan.workspace_limit', 1)
        );
});

test('plan sends a claimed trial offer only to an account that may use it', function (Closure $setup, bool $claims, bool $offered) {
    $trial = Offer::factory()->freeTrial()->create();
    completeWelcomeThroughSoftware($this->user);
    $setup($this->user);

    if ($claims) {
        $this->user->account->offers()->attach($trial, ['claimed_at' => now()]);
    }

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.plan'))
        ->assertOk()
        ->assertInertia(fn ($page) => $offered
            ? $page->where('offer.key', 'free_trial_7_days')
            : $page->where('offer', null));
})->with([
    'no claim pays the full price' => [fn () => null, false, false],
    'first-time subscriber' => [fn () => null, true, true],
    'retrying an incomplete checkout' => [fn (User $user) => $user->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_incomplete',
        'stripe_status' => 'incomplete',
        'stripe_price' => 'price_monthly_test',
        'quantity' => 1,
    ]), true, true],
    'former subscriber' => [fn (User $user) => $user->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_canceled',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_monthly_test',
        'quantity' => 1,
        'ends_at' => now()->subDay(),
    ]), true, false],
]);

test('plan store sends the owner to Stripe checkout on the Socials monthly price', function () {
    completeWelcomeThroughSoftware($this->user);
    $socials = Plan::where('slug', Slug::Socials)->firstOrFail();
    $socials->update([
        'stripe_monthly_price_id' => 'price_socials_monthly',
        'stripe_yearly_price_id' => 'price_socials_yearly',
    ]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->withArgs(fn ($account, $priceId, $cancelUrl, $plan): bool => $account->is($this->user->account)
            && $priceId === 'price_socials_monthly'
            && $cancelUrl === route('app.welcome.plan')
            && $plan->is($socials))
        ->andReturn(redirect('https://checkout.stripe.test/session'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.plan.store'))
        ->assertRedirect('https://checkout.stripe.test/session');
});

test('picking an AI app on the MCP step records which one', function (string $client) {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    attachCurrentWorkspace($this->user);
    subscribeAccount($this->user->account);

    $this->actingAs($this->user->fresh())
        ->postJson(route('app.welcome.mcp.connect-click'), ['client' => $client])
        ->assertNoContent();

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === WelcomeEvent::McpConnectClicked->value
        && data_get($event->payload, 'properties.client') === $client
        && data_get($event->payload, 'properties.account_id') === $this->user->account_id);
})->with(['claude', 'chatgpt', 'other']);

test('picking an AI app requires a known app', function (array $payload) {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.welcome.mcp.connect-click'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('client');

    Bus::assertNotDispatched(SendEvent::class);
})->with([
    'missing' => [[]],
    'unknown' => [['client' => 'gemini']],
]);

test('skipping the MCP step records it and goes to the schedule', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    attachCurrentWorkspace($this->user);
    subscribeAccount($this->user->account);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.mcp.skip'))
        ->assertRedirect(route('app.posts.index'));

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === WelcomeEvent::McpSkipped->value);
});

test('skipping the MCP step without app access goes back to Welcome and records nothing', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();

    $this->actingAs($this->user)
        ->get(route('app.welcome.mcp.skip'))
        ->assertRedirect(route('app.welcome.persona'));

    Bus::assertNotDispatched(SendEvent::class);
});

test('the MCP step gives the TryPost MCP URL and the owner connections', function () {
    attachCurrentWorkspace($this->user);
    subscribeAccount($this->user->account);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.mcp'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome/Mcp', false)
            ->where('mcpUrl', route('mcp.trypost'))
            ->where('connectedClients', [])
            ->missing('prompt')
        );
});

test('the MCP step lists the MCP apps the owner already connected', function () {
    $workspace = attachCurrentWorkspace($this->user);
    subscribeAccount($this->user->account);
    $clientId = mcpOauthClient('Claude');
    mcpAccessToken($this->user, $clientId, $workspace);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.mcp'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('connectedClients', 1)
            ->where('connectedClients.0.client_id', $clientId)
            ->where('connectedClients.0.name', 'Claude')
        );
});

test('the MCP step sends a user without a current workspace to create one', function () {
    subscribeAccount($this->user->account);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.mcp'))
        ->assertRedirect(route('app.workspaces.create'));
});

test('the MCP step works for a member and on self-hosted', function (Closure $actor) {
    $this->actingAs($actor($this->user))
        ->get(route('app.welcome.mcp'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('welcome/Mcp', false));
})->with([
    'member' => [function (User $owner): User {
        $workspace = attachCurrentWorkspace($owner);
        subscribeAccount($owner->account);
        $member = User::factory()->create(['account_id' => $owner->account_id]);
        $workspace->members()->attach($member->id, membershipPivot('member'));
        $member->update(['current_workspace_id' => $workspace->id]);

        return $member->fresh();
    }],
    'self-hosted' => [function (User $owner): User {
        config(['trypost.self_hosted' => true]);
        attachCurrentWorkspace($owner);

        return $owner->fresh();
    }],
]);

test('the MCP step sends an account without app access back to Welcome', function () {
    attachCurrentWorkspace($this->user);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.mcp'))
        ->assertRedirect(route('app.welcome.persona'));
});

test('plan store fails loudly when the Socials price is not configured', function () {
    completeWelcomeThroughSoftware($this->user);
    Plan::where('slug', Slug::Socials)->update(['stripe_monthly_price_id' => null]);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.plan.store'))
        ->assertStatus(500);
});

test('a welcome step stores a locale change and stays on the page', function () {
    completeWelcomeThroughGoals($this->user);

    $this->actingAs($this->user->fresh())
        ->from(route('app.welcome.software'))
        ->put(route('app.profile.language'), ['locale' => 'pt-BR'])
        ->assertRedirect(route('app.welcome.software'))
        ->assertCookieMissing('locale');

    expect($this->user->fresh()->locale)->toBe(Locale::PortugueseBrazil);
});

test('completed welcome steps remain reachable when going back', function () {
    attachCurrentWorkspace($this->user);
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
        'previous_software' => PreviousSoftware::Buffer->value,
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.persona'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('welcome/Persona', false));

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.goals'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('welcome/Goals', false));

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.software'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('welcome/Software', false)->where('selected', PreviousSoftware::Buffer->value));

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.plan'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('welcome/Plan', false));
});

test('welcome funnel captures every step before checkout.started', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    $plan = Plan::where('slug', Slug::Socials)->firstOrFail();
    $plan->update([
        'stripe_monthly_price_id' => 'price_monthly_test',
    ]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->andReturn(redirect('https://checkout.stripe.test/session'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.persona.store'), ['persona' => Persona::Agency->value])
        ->assertRedirect(route('app.welcome.goals'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.goals.store'), ['goals' => [Goal::SaveTime->value]])
        ->assertRedirect(route('app.welcome.software'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.software.store'), ['previous_software' => PreviousSoftware::None->value])
        ->assertRedirect(route('app.welcome.plan'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.plan.store'))
        ->assertRedirect('https://checkout.stripe.test/session');

    $funnel = WelcomeEvent::funnel();

    $captured = collect(Bus::dispatched(SendEvent::class))
        ->filter(fn (SendEvent $event): bool => $event->method === 'capture')
        ->map(fn (SendEvent $event): string => (string) data_get($event->payload, 'event'))
        ->filter(fn (string $event): bool => in_array($event, $funnel, true))
        ->values()
        ->all();

    expect($captured)->toBe($funnel);
});

test('welcome steps no longer share a workspace summary', function () {
    $this->actingAs($this->user)
        ->get(route('app.welcome.persona'))
        ->assertInertia(fn ($page) => $page
            ->component('welcome/Persona', false)
            ->missing('welcome')
        );
});

test('welcome steps redirect to calendar for subscribed accounts', function (string $routeName, string $method, array $payload = []) {
    subscribeAccount($this->user->account);

    $this->actingAs($this->user->fresh());

    $response = $method === 'get'
        ? $this->get(route($routeName))
        : $this->post(route($routeName), $payload);

    $response->assertRedirect(route('app.calendar'));
})->with([
    'persona' => ['app.welcome.persona', 'get'],
    'persona store' => ['app.welcome.persona.store', 'post', ['persona' => Persona::Agency->value]],
    'goals' => ['app.welcome.goals', 'get'],
    'goals store' => ['app.welcome.goals.store', 'post', ['goals' => [Goal::SaveTime->value]]],
    'software' => ['app.welcome.software', 'get'],
    'software store' => ['app.welcome.software.store', 'post', ['previous_software' => PreviousSoftware::Buffer->value]],
    'plan' => ['app.welcome.plan', 'get'],
    'plan store' => ['app.welcome.plan.store', 'post'],
]);

test('welcome redirects generic-trial accounts with app access to calendar', function () {
    config(['trypost.billing.require_card_for_trial' => false]);

    $this->user->account->forceFill([
        'trial_ends_at' => now()->addDays(8),
    ])->save();

    expect($this->user->account->fresh()->hasAppAccess())->toBeTrue()
        ->and($this->user->account->fresh()->subscribed(Account::SUBSCRIPTION_NAME))->toBeFalse();

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.persona'))
        ->assertRedirect(route('app.calendar'));
});

test('welcome steps redirect to calendar in self hosted mode', function (string $routeName, string $method, array $payload = []) {
    config(['trypost.self_hosted' => true]);

    $this->actingAs($this->user);

    $response = $method === 'get'
        ? $this->get(route($routeName))
        : $this->post(route($routeName), $payload);

    $response->assertRedirect(route('app.calendar'));
})->with([
    'persona' => ['app.welcome.persona', 'get'],
    'persona store' => ['app.welcome.persona.store', 'post', ['persona' => Persona::Agency->value]],
    'goals' => ['app.welcome.goals', 'get'],
    'goals store' => ['app.welcome.goals.store', 'post', ['goals' => [Goal::SaveTime->value]]],
    'software' => ['app.welcome.software', 'get'],
    'software store' => ['app.welcome.software.store', 'post', ['previous_software' => PreviousSoftware::Buffer->value]],
    'plan' => ['app.welcome.plan', 'get'],
    'plan store' => ['app.welcome.plan.store', 'post'],
]);

test('old onboarding routes are not registered', function (string $routeName) {
    expect(Route::has($routeName))->toBeFalse();
})->with([
    'index' => 'app.onboarding',
    'store' => 'app.onboarding.store',
    'skip mcp' => 'app.onboarding.mcp.skip',
    'complete' => 'app.onboarding.complete',
    'goals' => 'app.onboarding.goals',
    'goals store' => 'app.onboarding.goals.store',
    'connect' => 'app.onboarding.connect',
    'welcome connect' => 'app.welcome.connect',
    'welcome connect store' => 'app.welcome.connect.store',
    'welcome referral source' => 'app.welcome.referral-source',
    'checkout' => 'app.onboarding.checkout',
]);

test('members cannot start Stripe checkout from welcome', function (bool $withWorkspace) {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    completeWelcomeThroughGoals($member);

    if ($withWorkspace) {
        attachCurrentWorkspace($member);
    }

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($member->fresh())
        ->post(route('app.welcome.software.store'), ['previous_software' => PreviousSoftware::Buffer->value])
        ->assertRedirect(route('app.welcome.subscription-required'));
})->with([
    'without workspace' => [false],
    'with empty workspace' => [true],
]);

test('members without app access are held on the subscription required screen', function (string $routeName, string $method, array $payload = []) {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);

    $this->actingAs($member->fresh());

    $response = $method === 'get'
        ? $this->get(route($routeName))
        : $this->post(route($routeName), $payload);

    $response->assertRedirect(route('app.welcome.subscription-required'));
})->with([
    'persona' => ['app.welcome.persona', 'get'],
    'persona store' => ['app.welcome.persona.store', 'post', ['persona' => Persona::Agency->value]],
    'goals' => ['app.welcome.goals', 'get'],
    'goals store' => ['app.welcome.goals.store', 'post', ['goals' => [Goal::SaveTime->value]]],
    'software' => ['app.welcome.software', 'get'],
    'software store' => ['app.welcome.software.store', 'post', ['previous_software' => PreviousSoftware::Buffer->value]],
    'plan' => ['app.welcome.plan', 'get'],
    'plan store' => ['app.welcome.plan.store', 'post'],
]);

test('subscription required screen renders for members without app access', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);

    $this->actingAs($member->fresh())
        ->get(route('app.welcome.subscription-required'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome/SubscriptionRequired', false)
            ->where('ownerName', $this->user->name)
        );
});

test('subscription required screen sends owners back to the welcome flow', function () {
    $this->actingAs($this->user)
        ->get(route('app.welcome.subscription-required'))
        ->assertRedirect(route('app.welcome.persona'));
});

test('subscription required screen sends subscribed users to the calendar', function () {
    subscribeAccount($this->user->account);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.subscription-required'))
        ->assertRedirect(route('app.calendar'));
});

test('subscription required screen sends members with app access to the calendar', function () {
    ['owner' => $owner, 'member' => $member] = strandedMemberOnSharedAccount();
    subscribeAccount($owner->account);

    $this->actingAs($member)
        ->get(route('app.welcome.subscription-required'))
        ->assertRedirect(route('app.calendar'));
});

test('subscription required screen redirects to calendar in self hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $member = User::factory()->create(['account_id' => $this->user->account_id]);

    $this->actingAs($member->fresh())
        ->get(route('app.welcome.subscription-required'))
        ->assertRedirect(route('app.calendar'));
});

test('welcome sends members with app access to the calendar', function () {
    ['owner' => $owner, 'member' => $member] = strandedMemberOnSharedAccount();
    subscribeAccount($owner->account);

    $this->actingAs($member)
        ->get(route('app.welcome.persona'))
        ->assertRedirect(route('app.calendar'));
});

function completeWelcomeThroughGoals(User $user): void
{
    $user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ]);
}

function completeWelcomeThroughSoftware(User $user): void
{
    completeWelcomeThroughGoals($user);
    $user->update(['previous_software' => PreviousSoftware::Buffer->value]);
}

function attachCurrentWorkspace(User $user): Workspace
{
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    return $workspace;
}

test('the plan step sends the current offer', function () {
    completeWelcomeThroughSoftware($this->user);
    $offer = Offer::factory()->create(['slug' => 'black-friday-2026']);
    $this->user->account->offers()->attach($offer, ['claimed_at' => now()]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.plan'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('offer.key', 'black_friday_2026')
            ->where('offer.has_banner', false)
            ->where('offer.has_checkout_copy', false)
        );
});

test('the plan step sends no offer once it expired', function () {
    completeWelcomeThroughSoftware($this->user);
    $offer = Offer::factory()->create(['ends_at' => now()->addHour()]);
    $this->user->account->offers()->attach($offer, ['claimed_at' => now()]);

    $this->travel(2)->hours();

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.plan'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('offer', null)
        );
});

test('checkout.started records the offer slug', function () {
    config([
        'services.posthog.enabled' => true,
        'services.posthog.api_key' => 'phc_test',
    ]);
    Bus::fake();
    completeWelcomeThroughSoftware($this->user);
    Plan::where('slug', Slug::Socials)->update(['stripe_monthly_price_id' => 'price_socials_monthly']);
    $offer = Offer::factory()->create(['slug' => 'black-friday-2026']);
    $this->user->account->offers()->attach($offer, ['claimed_at' => now()]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->andReturn(redirect('https://checkout.stripe.test/session'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.plan.store'))
        ->assertRedirect('https://checkout.stripe.test/session');

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => data_get($event->payload, 'event') === CheckoutEvent::Started->value
        && data_get($event->payload, 'properties.offer') === 'black-friday-2026');
});

test('plan store hands checkout the offer it tracked', function () {
    completeWelcomeThroughSoftware($this->user);
    Plan::where('slug', Slug::Socials)->update(['stripe_monthly_price_id' => 'price_socials_monthly']);
    $offer = Offer::factory()->create();
    $this->user->account->offers()->attach($offer, ['claimed_at' => now()]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->withArgs(fn ($account, $priceId, $cancelUrl, $plan, $checkoutOffer = null): bool => $checkoutOffer?->is($offer) === true)
        ->andReturn(redirect('https://checkout.stripe.test/session'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.plan.store'))
        ->assertRedirect('https://checkout.stripe.test/session');
});

test('the plan step sends the banner only for an offer that shows one', function () {
    app('translator')->addLines([
        'offers.black_friday_2026.name' => 'Black Friday',
        'offers.black_friday_2026.headline' => '50% off your first 3 months',
    ], 'en');
    completeWelcomeThroughSoftware($this->user);
    $this->user->account->offers()->attach(Offer::factory()->create(['slug' => 'black-friday-2026']), ['claimed_at' => now()]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.plan'))
        ->assertInertia(fn ($page) => $page->where('offer.has_banner', false));
});

test('the plan step sends the offer copy only when it exists in the user\'s language', function (string $locale, bool $hasCopy) {
    app('translator')->addLines([
        'offers.black_friday_2026.name' => 'Black Friday',
        'offers.black_friday_2026.headline' => '50% off your first 3 months',
        'offers.black_friday_2026.price' => '$9.50',
        'offers.black_friday_2026.unit' => '/month',
        'offers.black_friday_2026.period' => 'for your first 3 months',
        'offers.black_friday_2026.checkout_button' => 'Activate for $9.50',
    ], 'en');
    completeWelcomeThroughSoftware($this->user);
    $this->user->update(['locale' => $locale]);
    $this->user->account->offers()->attach(Offer::factory()->withBanner()->create(['slug' => 'black-friday-2026']), ['claimed_at' => now()]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.plan'))
        ->assertInertia(fn ($page) => $page
            ->where('offer.has_banner', $hasCopy)
            ->where('offer.has_checkout_copy', $hasCopy)
            ->where('offer.has_unit', $hasCopy)
        );
})->with([
    'english copy for an english user' => ['en', true],
    'english copy only for a portuguese user' => ['pt-BR', false],
]);
