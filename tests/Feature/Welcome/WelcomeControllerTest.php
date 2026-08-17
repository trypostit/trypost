<?php

declare(strict_types=1);

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Enums\Plan\Slug;
use App\Enums\PostHog\CheckoutEvent;
use App\Enums\PostHog\WelcomeEvent;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Enums\User\Goal;
use App\Enums\User\Persona;
use App\Enums\User\ReferralSource;
use App\Enums\UserWorkspace\Role;
use App\Enums\Workspace\ContentLanguage;
use App\Jobs\PostHog\SendEvent;
use App\Models\Account;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
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

test('goals store saves choices mirrors them to PostHog and advances to referral source', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    $this->user->update(['persona' => Persona::Creator->value]);

    $goals = [Goal::AiContent->value, Goal::SaveTime->value];

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.goals.store'), ['goals' => $goals])
        ->assertRedirect(route('app.welcome.referral-source'));

    expect($this->user->fresh()->goals)->toBe($goals);
    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === WelcomeEvent::Goals->value
        && data_get($event->payload, 'properties.goals') === $goals);
});

test('completed welcome steps remain reachable when going back', function () {
    attachCurrentWorkspace($this->user);
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
        'referral_source' => ReferralSource::Google->value,
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
        ->get(route('app.welcome.referral-source'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('welcome/ReferralSource', false));

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.connect'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('welcome/Connect', false));
});

test('referral source redirects through incomplete prior steps', function (array $attributes, string $routeName) {
    $this->user->update($attributes);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.referral-source'))
        ->assertRedirect(route($routeName));
})->with([
    'missing persona' => [[], 'app.welcome.persona'],
    'missing goals' => [['persona' => Persona::Agency->value], 'app.welcome.goals'],
    'only removed goals' => [
        [
            'persona' => Persona::Agency->value,
            'goals' => ['team_collaboration', 'automate_api', 'track_performance'],
        ],
        'app.welcome.goals',
    ],
]);

test('referral source allows users who still have at least one current goal', function () {
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value, 'team_collaboration'],
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.referral-source'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('welcome/ReferralSource', false));
});

test('referral source renders after prior steps are complete', function () {
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.referral-source'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome/ReferralSource', false)
            ->has('sources', count(ReferralSource::cases()))
            ->where('sources', fn ($sources): bool => collect($sources)->contains(ReferralSource::GitHub->value)
                && collect($sources)->contains(ReferralSource::Threads->value)
                && collect($sources)->contains(ReferralSource::HackerNews->value)
                && collect($sources)->contains(ReferralSource::Directories->value)
                && collect($sources)->contains(ReferralSource::Founder->value))
            ->missing('plan')
        );
});

test('referral source requires a valid selection', function (array $payload) {
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ]);

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.referral-source.store'), $payload)
        ->assertSessionHasErrors('referral_source');

    expect($this->user->fresh()->referral_source)->toBeNull();
})->with([
    'missing' => [[]],
    'invalid' => [['referral_source' => 'not-a-source']],
]);

test('welcome funnel captures connect between referral and checkout.started', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    Plan::where('slug', Slug::Workspace)->firstOrFail()->update([
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
        ->assertRedirect(route('app.welcome.referral-source'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.referral-source.store'), [
            'referral_source' => ReferralSource::ProductHunt->value,
        ])
        ->assertRedirect(route('app.welcome.connect'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.connect.store'))
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

test('referral source store saves the source mirrors it to PostHog and advances to connect', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ]);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.referral-source.store'), [
            'referral_source' => ReferralSource::ProductHunt->value,
        ])
        ->assertRedirect(route('app.welcome.connect'));

    expect($this->user->fresh()->referral_source)->toBe(ReferralSource::ProductHunt);
    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === WelcomeEvent::Referral->value
        && data_get($event->payload, 'properties.referral_source') === ReferralSource::ProductHunt->value);
    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === CheckoutEvent::Started->value,
    );
});

test('connect redirects through incomplete prior steps', function (array $attributes, string $routeName, string $method, bool $withWorkspace) {
    $this->user->update($attributes);

    if ($withWorkspace) {
        attachCurrentWorkspace($this->user);
    }

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh());

    $response = $method === 'get'
        ? $this->get(route('app.welcome.connect'))
        : $this->post(route('app.welcome.connect.store'));

    $response->assertRedirect(route($routeName));
})->with([
    'get missing persona' => [[], 'app.welcome.persona', 'get'],
    'get missing goals' => [['persona' => Persona::Agency->value], 'app.welcome.goals', 'get'],
    'get missing referral' => [
        [
            'persona' => Persona::Agency->value,
            'goals' => [Goal::SaveTime->value],
        ],
        'app.welcome.referral-source',
        'get',
    ],
    'post missing persona' => [[], 'app.welcome.persona', 'post'],
    'post missing goals' => [['persona' => Persona::Agency->value], 'app.welcome.goals', 'post'],
    'post missing referral' => [
        [
            'persona' => Persona::Agency->value,
            'goals' => [Goal::SaveTime->value],
        ],
        'app.welcome.referral-source',
        'post',
    ],
    'get only removed goals' => [
        [
            'persona' => Persona::Agency->value,
            'goals' => ['team_collaboration', 'automate_api', 'track_performance'],
            'referral_source' => ReferralSource::Google->value,
        ],
        'app.welcome.goals',
        'get',
    ],
    'post only removed goals' => [
        [
            'persona' => Persona::Agency->value,
            'goals' => ['team_collaboration', 'automate_api', 'track_performance'],
            'referral_source' => ReferralSource::Google->value,
        ],
        'app.welcome.goals',
        'post',
    ],
])->with([
    'without workspace' => [false],
    'with empty workspace' => [true],
]);

test('connect returns 404 when prior steps are complete but the user has no workspace', function () {
    completeWelcomeThroughReferral($this->user);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.connect'))
        ->assertNotFound();

    $this->actingAs($this->user->fresh())
        ->from(route('app.welcome.connect'))
        ->post(route('app.welcome.connect.store'))
        ->assertNotFound();
});

test('connect renders the network grid when the workspace has no accounts', function () {
    completeWelcomeThroughReferral($this->user);
    attachCurrentWorkspace($this->user);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.connect'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome/Connect', false)
            ->has('platforms', count(SocialPlatform::connectableOptions()))
            ->where('accounts', [])
        );
});

test('connect renders connected accounts for the current workspace', function () {
    completeWelcomeThroughReferral($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.connect'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome/Connect', false)
            ->has('platforms', count(SocialPlatform::connectableOptions()))
            ->has('accounts', 1)
            ->where('accounts.0.id', $account->id)
            ->where('accounts.0.platform', SocialPlatform::LinkedIn->value)
            ->where('accounts.0.status', Status::Connected->value)
        );
});

test('connect copy exists in every locale', function (string $locale) {
    expect(__('welcome.connect.title', [], $locale))->not->toBe('welcome.connect.title')
        ->and(__('welcome.connect.description', [], $locale))->not->toBe('welcome.connect.description')
        ->and(__('welcome.connect.required', [], $locale))->not->toBe('welcome.connect.required');
})->with(ContentLanguage::values());

test('connect store requires a connected social account', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    completeWelcomeThroughReferral($this->user);
    attachCurrentWorkspace($this->user);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.connect.store'))
        ->assertSessionHasErrors('connect');

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === WelcomeEvent::Connect->value,
    );
    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === CheckoutEvent::Started->value,
    );
});

test('connect store rejects disconnected or expired social accounts', function () {
    completeWelcomeThroughReferral($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->disconnected()->create(['workspace_id' => $workspace->id]);
    SocialAccount::factory()->x()->tokenExpired()->create(['workspace_id' => $workspace->id]);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.connect.store'))
        ->assertSessionHasErrors('connect');
});

test('connect store ignores social accounts on another workspace', function () {
    completeWelcomeThroughReferral($this->user);
    attachCurrentWorkspace($this->user);

    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $otherWorkspace->id]);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.connect.store'))
        ->assertSessionHasErrors('connect');
});

test('connect store starts Stripe checkout when a social account is connected', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    completeWelcomeThroughReferral($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    Plan::where('slug', Slug::Workspace)->firstOrFail()->update([
        'stripe_monthly_price_id' => 'price_monthly_test',
    ]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->withArgs(fn (Account $account, string $priceId, string $cancelUrl): bool => $account->is($this->user->account)
            && $priceId === 'price_monthly_test'
            && $cancelUrl === route('app.welcome.connect'))
        ->andReturn(redirect('https://checkout.stripe.test/session'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.connect.store'))
        ->assertRedirect('https://checkout.stripe.test/session');

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === WelcomeEvent::Connect->value
        && data_get($event->payload, 'properties.platforms') === [SocialPlatform::LinkedIn->value]);
});

test('connect store captures checkout.started with the plan name and interval', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    completeWelcomeThroughReferral($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    $plan = Plan::where('slug', Slug::Workspace)->firstOrFail();
    $plan->update(['stripe_monthly_price_id' => 'price_monthly_test']);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->andReturn(redirect('https://checkout.stripe.test/session'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.connect.store'));

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === CheckoutEvent::Started->value
        && data_get($event->payload, 'properties.plan_name') === $plan->name
        && data_get($event->payload, 'properties.interval') === 'monthly');
});

test('connect store does not capture checkout.started when Stripe checkout creation fails', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    completeWelcomeThroughReferral($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    Plan::where('slug', Slug::Workspace)->firstOrFail()->update([
        'stripe_monthly_price_id' => 'price_monthly_test',
    ]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->andThrow(new RuntimeException('Stripe checkout could not be created.'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.connect.store'));

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === WelcomeEvent::Connect->value,
    );
    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === CheckoutEvent::Started->value,
    );
});

test('connect store still redirects to stripe when posthog capture fails', function () {
    Exceptions::fake();
    completeWelcomeThroughReferral($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    Plan::where('slug', Slug::Workspace)->firstOrFail()->update([
        'stripe_monthly_price_id' => 'price_monthly_test',
    ]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->andReturn(redirect('https://checkout.stripe.test/session'));

    $this->mock(PostHogService::class)
        ->shouldReceive('capture')
        ->andThrow(new RuntimeException('PostHog is down.'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.connect.store'))
        ->assertRedirect('https://checkout.stripe.test/session');

    Exceptions::assertReported(RuntimeException::class);
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
    'referral source' => ['app.welcome.referral-source', 'get'],
    'referral source store' => ['app.welcome.referral-source.store', 'post', ['referral_source' => ReferralSource::Google->value]],
    'connect' => ['app.welcome.connect', 'get'],
    'connect store' => ['app.welcome.connect.store', 'post'],
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
    'referral source' => ['app.welcome.referral-source', 'get'],
    'referral source store' => ['app.welcome.referral-source.store', 'post', ['referral_source' => ReferralSource::Google->value]],
    'connect' => ['app.welcome.connect', 'get'],
    'connect store' => ['app.welcome.connect.store', 'post'],
]);

test('old onboarding icp routes are not registered', function (string $routeName) {
    expect(Route::has($routeName))->toBeFalse();
})->with([
    // `app.onboarding` is reused for the post-subscription activation checklist.
    'store' => 'app.onboarding.store',
    'goals' => 'app.onboarding.goals',
    'goals store' => 'app.onboarding.goals.store',
    'referral source' => 'app.onboarding.referral-source',
    'referral source store' => 'app.onboarding.referral-source.store',
    'connect' => 'app.onboarding.connect',
    'checkout' => 'app.onboarding.checkout',
]);

test('members cannot start Stripe checkout from welcome', function (bool $withWorkspace) {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    completeWelcomeThroughReferral($member);

    if ($withWorkspace) {
        attachCurrentWorkspace($member);
    }

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($member->fresh())
        ->get(route('app.welcome.connect'))
        ->assertRedirect(route('app.welcome.subscription-required'));

    $this->actingAs($member->fresh())
        ->post(route('app.welcome.connect.store'))
        ->assertRedirect(route('app.welcome.subscription-required'));
})->with([
    'without workspace' => [false],
    'with empty workspace' => [true],
]);

test('subscribed owners skip connect validation and go to calendar', function () {
    subscribeAccount($this->user->account);
    completeWelcomeThroughReferral($this->user);
    attachCurrentWorkspace($this->user);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.connect.store'))
        ->assertRedirect(route('app.calendar'));
});

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
    'referral source' => ['app.welcome.referral-source', 'get'],
    'referral source store' => ['app.welcome.referral-source.store', 'post', ['referral_source' => ReferralSource::Google->value]],
    'connect' => ['app.welcome.connect', 'get'],
    'connect store' => ['app.welcome.connect.store', 'post'],
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

test('connect store fails loudly when the monthly price is not configured', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    completeWelcomeThroughReferral($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    Plan::where('slug', Slug::Workspace)->update(['stripe_monthly_price_id' => null]);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.connect.store'))
        ->assertServerError();

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === WelcomeEvent::Connect->value,
    );
    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === CheckoutEvent::Started->value,
    );
});

function completeWelcomeThroughReferral(User $user): void
{
    $user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
        'referral_source' => ReferralSource::ProductHunt->value,
    ]);
}

function attachCurrentWorkspace(User $user): Workspace
{
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    return $workspace;
}
