<?php

declare(strict_types=1);

use App\Enums\PostHog\WelcomeEvent;
use App\Enums\User\ReferralSource;
use App\Jobs\PostHog\SendEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create(['referral_source' => null]);
    $workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($this->user->account);
});

test('a user inside the app who has not answered is asked where they found us', function () {
    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'referralSources',
            array_map(fn (ReferralSource $source): string => $source->value, ReferralSource::cases()),
        ));
});

test('the question is not shared once answered, on self-hosted or before app access', function (Closure $setup) {
    $setup($this->user);

    $this->actingAs($this->user->fresh())
        ->followingRedirects()
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('referralSources', null));
})->with([
    'answered' => [fn (User $user) => $user->update(['referral_source' => ReferralSource::Google->value])],
    'self-hosted' => [fn () => config(['trypost.self_hosted' => true])],
    'no app access' => [fn (User $user) => $user->account->subscriptions()->delete()],
]);

test('answering stores the source, mirrors it to PostHog and goes back', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();

    $this->actingAs($this->user->fresh())
        ->from(route('app.calendar'))
        ->post(route('app.referral-source.store'), ['referral_source' => ReferralSource::ProductHunt->value])
        ->assertRedirect(route('app.calendar'));

    expect($this->user->fresh()->referral_source)->toBe(ReferralSource::ProductHunt);
    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === WelcomeEvent::Referral->value
        && data_get($event->payload, 'properties.referral_source') === ReferralSource::ProductHunt->value);

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertInertia(fn ($page) => $page->where('referralSources', null));
});

test('answering requires a valid source', function (array $payload) {
    $this->actingAs($this->user->fresh())
        ->post(route('app.referral-source.store'), $payload)
        ->assertSessionHasErrors('referral_source');

    expect($this->user->fresh()->referral_source)->toBeNull();
})->with([
    'missing' => [[]],
    'invalid' => [['referral_source' => 'not-a-source']],
]);
