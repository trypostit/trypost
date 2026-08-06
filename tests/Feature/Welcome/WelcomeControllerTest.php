<?php

declare(strict_types=1);

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Enums\Plan\Slug;
use App\Enums\PostHog\WelcomeEvent;
use App\Enums\User\Goal;
use App\Enums\User\Persona;
use App\Enums\User\ReferralSource;
use App\Jobs\PostHog\SendEvent;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
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
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.persona'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('welcome/Persona', false));

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.goals'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('welcome/Goals', false));
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
    $plan = Plan::where('slug', Slug::Workspace)->firstOrFail();

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.referral-source'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome/ReferralSource', false)
            ->has('sources', count(ReferralSource::cases()))
            ->where('plan.name', $plan->name)
            ->where('plan.interval', 'monthly')
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

test('referral source store saves the source and starts Stripe checkout without a social account', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ]);

    Plan::where('slug', Slug::Workspace)->firstOrFail()->update([
        'stripe_monthly_price_id' => 'price_monthly_test',
    ]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->withArgs(fn (Account $account, string $priceId, string $cancelUrl): bool => $account->is($this->user->account)
            && $priceId === 'price_monthly_test'
            && $cancelUrl === route('app.welcome.referral-source'))
        ->andReturn(redirect('https://checkout.stripe.test/session'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.referral-source.store'), [
            'referral_source' => ReferralSource::ProductHunt->value,
        ])
        ->assertRedirect('https://checkout.stripe.test/session');

    expect($this->user->fresh()->referral_source)->toBe(ReferralSource::ProductHunt);
    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === WelcomeEvent::Referral->value
        && data_get($event->payload, 'properties.referral_source') === ReferralSource::ProductHunt->value);
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
]);

test('old onboarding icp routes are not registered', function (string $routeName) {
    expect(Route::has($routeName))->toBeFalse();
})->with([
    'root' => 'app.onboarding',
    'store' => 'app.onboarding.store',
    'goals' => 'app.onboarding.goals',
    'goals store' => 'app.onboarding.goals.store',
    'referral source' => 'app.onboarding.referral-source',
    'referral source store' => 'app.onboarding.referral-source.store',
    'connect' => 'app.onboarding.connect',
    'checkout' => 'app.onboarding.checkout',
]);

test('members cannot start Stripe checkout from welcome', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $member->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ]);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    // Members never reach the referral step — they are held on the
    // subscription-required screen before any checkout attempt.
    $this->actingAs($member->fresh())
        ->get(route('app.welcome.referral-source'))
        ->assertRedirect(route('app.welcome.subscription-required'));

    $this->actingAs($member->fresh())
        ->post(route('app.welcome.referral-source.store'), [
            'referral_source' => ReferralSource::Google->value,
        ])
        ->assertRedirect(route('app.welcome.subscription-required'));

    expect($member->fresh()->referral_source)->toBeNull();
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

test('referral source store fails loudly when the monthly price is not configured', function () {
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ]);
    Plan::where('slug', Slug::Workspace)->update(['stripe_monthly_price_id' => null]);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.referral-source.store'), [
            'referral_source' => ReferralSource::Google->value,
        ])
        ->assertServerError();
});
