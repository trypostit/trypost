<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Timezone;

beforeEach(fn () => config()->set('trypost.self_hosted', false));

test('existing and new users default to UTC', function () {
    $user = User::factory()->create();

    expect($user->fresh()->timezone)->toBe('UTC');
});

test('registration stores the browser time zone', function () {
    $this->post(route('register.store'), [
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => strongPassword(),
        'locale' => 'en',
        'timezone' => 'Europe/Warsaw',
    ]);

    expect(User::query()->where('email', 'ana@example.com')->value('timezone'))->toBe('Europe/Warsaw');
});

test('registration maps a legacy time zone alias to its canonical identifier', function () {
    $this->post(route('register.store'), [
        'name' => 'Ravi',
        'email' => 'ravi@example.com',
        'password' => strongPassword(),
        'locale' => 'en',
        'timezone' => 'Asia/Calcutta',
    ]);

    expect(User::query()->where('email', 'ravi@example.com')->value('timezone'))->toBe('Asia/Kolkata');
});

test('normalize maps legacy aliases to canonical identifiers', function (string $alias, string $canonical) {
    expect(Timezone::normalize($alias))->toBe($canonical);
})->with([
    'Calcutta' => ['Asia/Calcutta', 'Asia/Kolkata'],
    'Kiev' => ['Europe/Kiev', 'Europe/Kyiv'],
    'Saigon' => ['Asia/Saigon', 'Asia/Ho_Chi_Minh'],
]);

test('normalize maps an alias missing from PHP\'s time zone list', function (string $alias, string $canonical) {
    expect(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))->not->toContain($alias)
        ->and(Timezone::normalize($alias))->toBe($canonical);
})->with([
    'East-Saskatchewan' => ['Canada/East-Saskatchewan', 'America/Regina'],
    'Pacific-New' => ['US/Pacific-New', 'America/Los_Angeles'],
]);

test('registration maps an alias missing from PHP\'s time zone list', function () {
    $this->post(route('register.store'), [
        'name' => 'Ravi',
        'email' => 'ravi@example.com',
        'password' => strongPassword(),
        'locale' => 'en',
        'timezone' => 'Canada/East-Saskatchewan',
    ]);

    expect(User::query()->where('email', 'ravi@example.com')->value('timezone'))->toBe('America/Regina');
});

test('registration falls back to UTC for a missing or invalid time zone', function (?string $timezone) {
    $payload = [
        'name' => 'Bo',
        'email' => 'bo@example.com',
        'password' => strongPassword(),
        'locale' => 'en',
    ];

    if ($timezone !== null) {
        $payload['timezone'] = $timezone;
    }

    $this->post(route('register.store'), $payload)->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'bo@example.com')->value('timezone'))->toBe('UTC');
})->with([null, '', 'Mars/Olympus', '<script>', str_repeat('x', 200)]);

test('a user can change their time zone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('app.settings.preferences.update'), ['timezone' => 'America/Sao_Paulo'])
        ->assertRedirect();

    expect($user->fresh()->timezone)->toBe('America/Sao_Paulo');
});

test('changing the time zone rejects invalid identifiers', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('app.settings.preferences.update'), ['timezone' => 'Nowhere/Land'])
        ->assertSessionHasErrors('timezone');

    expect($user->fresh()->timezone)->toBe('UTC');
});

test('the shared auth user exposes the time zone', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Tokyo']);

    $this->actingAs($user)
        ->get(route('app.profile.edit'))
        ->assertInertia(fn ($page) => $page->where('auth.user.timezone', 'Asia/Tokyo'));
});

test('the preferences page receives the time zone options', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('app.settings.preferences'))
        ->assertInertia(fn ($page) => $page->has('timezones')->where('timezones.0.value', fn ($value) => is_string($value)));
});

test('timezone normalize and options', function () {
    expect(Timezone::normalize('Europe/Warsaw'))->toBe('Europe/Warsaw')
        ->and(Timezone::normalize(null))->toBe('UTC')
        ->and(Timezone::normalize('bogus'))->toBe('UTC');

    $saoPaulo = collect(Timezone::options())->firstWhere('value', 'America/Sao_Paulo');

    expect($saoPaulo)->toMatchArray(['value' => 'America/Sao_Paulo', 'label' => 'Sao Paulo'])
        ->and($saoPaulo['offset'])->toStartWith('GMT');
});
