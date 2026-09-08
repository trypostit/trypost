<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Jobs\PostHog\SyncUser;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * @param  array<string, string|null>  $overrides
 */
function loginWith(User $user, array $overrides = []): TestResponse
{
    return test()->post(route('login.store'), array_merge([
        'email' => $user->email,
        'password' => 'password',
        'locale' => 'en',
    ], $overrides));
}

test('logging in stores the locale picked on the login screen', function () {
    $user = User::factory()->create(['locale' => Locale::English]);

    loginWith($user, ['locale' => 'pt-BR'])->assertSessionHasNoErrors();

    expect($user->refresh()->locale)->toBe(Locale::PortugueseBrazil);
});

test('the locale is required, since the login form always submits one', function () {
    $user = User::factory()->create(['locale' => Locale::Japanese]);

    loginWith($user, ['locale' => null])->assertSessionHasErrors('locale');

    $this->assertGuest();
    expect($user->refresh()->locale)->toBe(Locale::Japanese);
});

test('an unsupported locale is rejected rather than stored', function () {
    $user = User::factory()->create(['locale' => Locale::Japanese]);

    loginWith($user, ['locale' => 'sv'])->assertSessionHasErrors('locale');

    $this->assertGuest();
    expect($user->refresh()->locale)->toBe(Locale::Japanese);
});

test('logging in pushes the locale to PostHog', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
    Queue::fake();

    $user = User::factory()->create(['locale' => Locale::English]);

    loginWith($user, ['locale' => 'de'])->assertSessionHasNoErrors();

    Queue::assertPushed(SyncUser::class, fn (SyncUser $job) => $job->userId === (string) $user->id);
});

test('the app renders in the locale chosen at login', function () {
    $user = User::factory()->create(['locale' => Locale::English]);

    loginWith($user, ['locale' => 'ja']);
    $this->get(route('app.profile.edit'))->assertOk();

    expect(app()->getLocale())->toBe('ja');
});
