<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(fn () => config()->set('trypost.self_hosted', false));

test('email registration saves ad click ids from the register page query string', function () {
    $clickIds = [
        'gclid' => 'gclid-value',
        'fbclid' => 'fbclid-value',
        'li_fat_id' => 'li-fat-id-value',
        'ttclid' => 'ttclid-value',
        'rdt_cid' => 'rdt-cid-value',
        'epik' => 'epik-value',
    ];

    $this->get(route('register', $clickIds));

    $this->post(route('register.store'), [
        'name' => 'Click User',
        'email' => 'click@example.com',
        'password' => 'Password123!',
    ])
        ->assertRedirect(route('register.success', $clickIds, absolute: false));

    $this->assertDatabaseHas('users', [
        'email' => 'click@example.com',
        ...$clickIds,
    ]);
});

test('email registration without click ids saves null columns', function () {
    $this->post(route('register.store'), [
        'name' => 'No Click User',
        'email' => 'no-click@example.com',
        'password' => 'Password123!',
    ]);

    $this->assertDatabaseHas('users', [
        'email' => 'no-click@example.com',
        'gclid' => null,
        'fbclid' => null,
        'li_fat_id' => null,
        'ttclid' => null,
        'rdt_cid' => null,
        'epik' => null,
    ]);
});

test('click id values longer than 255 characters are truncated before being stored', function () {
    $longValue = str_repeat('a', 300);

    $this->get(route('register', ['gclid' => $longValue]));

    $this->post(route('register.store'), [
        'name' => 'Long Click User',
        'email' => 'long-click@example.com',
        'password' => 'Password123!',
    ]);

    $user = User::where('email', 'long-click@example.com')->first();

    expect(mb_strlen($user->gclid))->toBe(255);
});

test('google registration saves ad click ids captured before the oauth round-trip', function () {
    $clickIds = ['gclid' => 'g-click', 'fbclid' => 'fb-click'];

    $this->get(route('auth.google.redirect', $clickIds));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'g-click-user';
    $socialiteUser->name = 'Google Click';
    $socialiteUser->email = 'google-click@example.com';

    Socialite::shouldReceive('driver')
        ->with('google-auth')
        ->andReturn($driver = Mockery::mock());

    $driver->shouldReceive('user')
        ->andReturn($socialiteUser);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('register.success', $clickIds, absolute: false));

    $this->assertDatabaseHas('users', [
        'email' => 'google-click@example.com',
        ...$clickIds,
    ]);
});

test('github registration saves ad click ids captured before the oauth round-trip', function () {
    $clickIds = ['li_fat_id' => 'li-click', 'ttclid' => 'tt-click'];

    $this->get(route('auth.github.redirect', $clickIds));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'gh-click-user';
    $socialiteUser->name = 'GitHub Click';
    $socialiteUser->email = 'github-click@example.com';

    Socialite::shouldReceive('driver')
        ->with('github')
        ->andReturn($driver = Mockery::mock());

    $driver->shouldReceive('scopes')
        ->andReturnSelf();

    $driver->shouldReceive('user')
        ->andReturn($socialiteUser);

    $this->get(route('auth.github.callback'))
        ->assertRedirect(route('register.success', $clickIds, absolute: false));

    $this->assertDatabaseHas('users', [
        'email' => 'github-click@example.com',
        ...$clickIds,
    ]);
});

test('existing google user login consumes the click id session so it does not leak to a later signup', function () {
    User::factory()->create([
        'email' => 'existing-click@example.com',
        'google_id' => 'g-existing-click',
    ]);

    $this->get(route('auth.google.redirect', ['gclid' => 'stale-click']));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'g-existing-click';
    $socialiteUser->name = 'Existing Click User';
    $socialiteUser->email = 'existing-click@example.com';

    Socialite::shouldReceive('driver')
        ->with('google-auth')
        ->andReturn($driver = Mockery::mock());

    $driver->shouldReceive('user')
        ->andReturn($socialiteUser);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('app.home'));

    expect(session()->get('click_ids'))->toBeNull();
});

test('click ids and utm parameters are both saved when present together', function () {
    $this->get(route('register', [
        'utm_source' => 'google',
        'gclid' => 'mixed-click',
    ]));

    $this->post(route('register.store'), [
        'name' => 'Mixed User',
        'email' => 'mixed@example.com',
        'password' => 'Password123!',
    ]);

    $this->assertDatabaseHas('users', [
        'email' => 'mixed@example.com',
        'utm_source' => 'google',
        'gclid' => 'mixed-click',
    ]);
});
