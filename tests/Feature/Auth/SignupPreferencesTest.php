<?php

declare(strict_types=1);

use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(fn () => config([
    'trypost.self_hosted' => false,
    'trypost.google_auth_enabled' => true,
    'trypost.github_auth_enabled' => true,
]));

test('email registration stores the zone, week start and clock the browser reported', function () {
    $this->post(route('register.store'), [
        'name' => 'Bia',
        'email' => 'bia@example.com',
        'password' => 'Password123!',
        'locale' => 'pt-BR',
        'timezone' => 'America/Sao_Paulo',
        'week_starts_on' => 'sunday',
        'time_format' => '12h',
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'bia@example.com')->sole();

    expect($user->timezone)->toBe('America/Sao_Paulo')
        ->and($user->week_starts_on)->toBe(WeekStart::Sunday)
        ->and($user->time_format)->toBe(TimeFormat::TwelveHour);
});

test('email registration without detection falls back to UTC, Monday and the language clock', function () {
    $this->post(route('register.store'), [
        'name' => 'Dirk',
        'email' => 'dirk@example.com',
        'password' => 'Password123!',
        'locale' => 'de',
        'week_starts_on' => '',
        'time_format' => '',
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'dirk@example.com')->sole();

    expect($user->timezone)->toBe('UTC')
        ->and($user->week_starts_on)->toBe(WeekStart::Monday)
        ->and($user->time_format)->toBe(TimeFormat::TwentyFourHour);
});

test('email registration rejects a week start or clock outside the allowed values', function (string $field, string $value) {
    $this->post(route('register.store'), [
        'name' => 'Eve',
        'email' => 'eve@example.com',
        'password' => 'Password123!',
        'locale' => 'en',
        $field => $value,
    ])->assertSessionHasErrors($field);
})->with([
    ['week_starts_on', 'friday'],
    ['time_format', '36h'],
]);

function signupPreferencesSocialite(string $driver, string $id, string $email): void
{
    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = $id;
    $socialiteUser->name = 'Social Signup';
    $socialiteUser->email = $email;

    Socialite::shouldReceive('driver')->with($driver)->andReturn($mock = Mockery::mock());
    $mock->shouldReceive('scopes')->andReturnSelf();
    $mock->shouldReceive('user')->andReturn($socialiteUser);
}

test('google signup stores the preferences sent to the OAuth start', function () {
    $this->get(route('auth.google.redirect', ['timezone' => 'Asia/Tokyo', 'week_starts_on' => 'sunday', 'time_format' => '24h']));
    signupPreferencesSocialite('google-auth', 'g-prefs', 'google-prefs@example.com');

    $this->get(route('auth.google.callback'))->assertRedirect(route('app.welcome', absolute: false));

    $user = User::query()->where('email', 'google-prefs@example.com')->sole();

    expect($user->timezone)->toBe('Asia/Tokyo')
        ->and($user->week_starts_on)->toBe(WeekStart::Sunday)
        ->and($user->time_format)->toBe(TimeFormat::TwentyFourHour);
});

test('github signup with unusable detection values falls back instead of failing', function () {
    $this->get(route('auth.github.redirect', ['timezone' => 'Mars/Olympus', 'week_starts_on' => 'friday', 'time_format' => '36h']));
    signupPreferencesSocialite('github', 'gh-prefs', 'github-prefs@example.com');

    $this->get(route('auth.github.callback'))->assertRedirect(route('app.welcome', absolute: false));

    $user = User::query()->where('email', 'github-prefs@example.com')->sole();

    expect($user->timezone)->toBe('UTC')
        ->and($user->week_starts_on)->toBe(WeekStart::Monday)
        ->and($user->time_format)->toBe(TimeFormat::TwelveHour);
});

test('an existing user logging in with google does not leave the detection in the session', function () {
    User::factory()->create(['email' => 'returning@example.com', 'google_id' => 'g-returning', 'timezone' => 'Europe/Lisbon']);

    $this->get(route('auth.google.redirect', ['timezone' => 'Asia/Tokyo']));
    signupPreferencesSocialite('google-auth', 'g-returning', 'returning@example.com');

    $this->get(route('auth.google.callback'))->assertSessionMissing('signup_preferences');

    expect(User::query()->where('email', 'returning@example.com')->sole()->timezone)->toBe('Europe/Lisbon');
});
