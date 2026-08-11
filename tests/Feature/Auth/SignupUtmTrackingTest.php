<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(fn () => config()->set('trypost.self_hosted', false));

test('email registration saves utm parameters from the register page query string', function () {
    $utms = [
        'utm_source' => 'peerlist',
        'utm_medium' => 'social',
        'utm_campaign' => 'spring-launch',
        'utm_term' => 'social-platform',
        'utm_content' => 'cta-button',
    ];

    $this->get(route('register', $utms));

    $this->post(route('register.store'), [
        'name' => 'UTM User',
        'email' => 'utm@example.com',
        'password' => 'Password123!',
    ])
        ->assertRedirect(route('register.success', $utms, absolute: false));

    $this->assertDatabaseHas('users', [
        'email' => 'utm@example.com',
        ...$utms,
    ]);
});

test('email registration without utm parameters saves null utm columns and redirects without query string', function () {
    $this->post(route('register.store'), [
        'name' => 'No UTM User',
        'email' => 'no-utm@example.com',
        'password' => 'Password123!',
    ])
        ->assertRedirect(route('register.success', absolute: false));

    $this->assertDatabaseHas('users', [
        'email' => 'no-utm@example.com',
        'utm_source' => null,
        'utm_medium' => null,
        'utm_campaign' => null,
        'utm_term' => null,
        'utm_content' => null,
    ]);
});

test('email registration strips non-utm query params from the success redirect', function () {
    $this->get(route('register', [
        'utm_source' => 'peerlist',
        'foo' => 'bar',
        'ref' => '123',
    ]));

    $this->post(route('register.store'), [
        'name' => 'Strip Test',
        'email' => 'strip@example.com',
        'password' => 'Password123!',
    ])
        ->assertRedirect(route('register.success', ['utm_source' => 'peerlist'], absolute: false));
});

test('google registration saves utm parameters captured before the oauth round-trip', function () {
    $utms = [
        'utm_source' => 'twitter',
        'utm_medium' => 'paid',
        'utm_campaign' => 'q1-growth',
    ];

    $this->get(route('auth.google.redirect', $utms));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'g-utm';
    $socialiteUser->name = 'Google UTM';
    $socialiteUser->email = 'google-utm@example.com';

    Socialite::shouldReceive('driver')
        ->with('google-auth')
        ->andReturn($driver = Mockery::mock());

    $driver->shouldReceive('user')
        ->andReturn($socialiteUser);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('register.success', $utms, absolute: false));

    $this->assertDatabaseHas('users', [
        'email' => 'google-utm@example.com',
        ...$utms,
        'utm_term' => null,
        'utm_content' => null,
    ]);
});

test('utm parameters captured on the register page survive a google oauth round-trip', function () {
    $utms = ['utm_source' => 'newsletter', 'utm_medium' => 'email'];

    $this->get(route('register', $utms));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'g-cross';
    $socialiteUser->name = 'Cross Flow';
    $socialiteUser->email = 'cross-flow@example.com';

    Socialite::shouldReceive('driver')
        ->with('google-auth')
        ->andReturn($driver = Mockery::mock());

    $driver->shouldReceive('user')
        ->andReturn($socialiteUser);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('register.success', $utms, absolute: false));

    $this->assertDatabaseHas('users', [
        'email' => 'cross-flow@example.com',
        ...$utms,
    ]);
});

test('existing google user login consumes the utm session so utms do not leak to a later signup', function () {
    User::factory()->create([
        'email' => 'existing@example.com',
        'google_id' => 'g-existing',
    ]);

    $this->get(route('auth.google.redirect', ['utm_source' => 'twitter']));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'g-existing';
    $socialiteUser->name = 'Existing User';
    $socialiteUser->email = 'existing@example.com';

    Socialite::shouldReceive('driver')
        ->with('google-auth')
        ->andReturn($driver = Mockery::mock());

    $driver->shouldReceive('user')
        ->andReturn($socialiteUser);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('app.home'));

    expect(session()->get('attribution_parameters'))->toBeNull();
});

test('invitation registration does not include utm parameters in its redirect', function () {
    $this->get(route('register', ['utm_source' => 'email']));

    $this->post(route('register.store'), [
        'name' => 'Invited User',
        'email' => 'invited@example.com',
        'password' => 'Password123!',
        'redirect' => '/invites/some-token',
    ])
        ->assertRedirect('/invites/some-token');
});

test('utm values longer than 255 characters are truncated before being stored', function () {
    $longValue = str_repeat('a', 300);

    $this->get(route('register', ['utm_source' => $longValue]));

    $this->post(route('register.store'), [
        'name' => 'Long UTM User',
        'email' => 'long-utm@example.com',
        'password' => 'Password123!',
    ]);

    $user = User::where('email', 'long-utm@example.com')->first();

    expect(mb_strlen($user->utm_source))->toBe(255);
});

test('email registration captures the requesting ip address', function () {
    $this->post(route('register.store'), [
        'name' => 'IP User',
        'email' => 'ip@example.com',
        'password' => 'Password123!',
    ]);

    $user = User::where('email', 'ip@example.com')->first();

    expect($user->registration_ip)->not->toBeNull();
});

test('google registration captures the requesting ip address', function () {
    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'g-ip';
    $socialiteUser->name = 'Google IP';
    $socialiteUser->email = 'google-ip@example.com';

    Socialite::shouldReceive('driver')
        ->with('google-auth')
        ->andReturn($driver = Mockery::mock());

    $driver->shouldReceive('user')
        ->andReturn($socialiteUser);

    $this->get(route('auth.google.callback'));

    $user = User::where('email', 'google-ip@example.com')->first();

    expect($user->registration_ip)->not->toBeNull();
});

test('github registration saves utm parameters captured before the oauth round-trip', function () {
    $utms = [
        'utm_source' => 'hackernews',
        'utm_medium' => 'organic',
        'utm_campaign' => 'launch-week',
    ];

    $this->get(route('auth.github.redirect', $utms));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'gh-utm';
    $socialiteUser->name = 'GitHub UTM';
    $socialiteUser->email = 'github-utm@example.com';

    Socialite::shouldReceive('driver')
        ->with('github')
        ->andReturn($driver = Mockery::mock());

    $driver->shouldReceive('scopes')
        ->andReturnSelf();

    $driver->shouldReceive('user')
        ->andReturn($socialiteUser);

    $this->get(route('auth.github.callback'))
        ->assertRedirect(route('register.success', $utms, absolute: false));

    $this->assertDatabaseHas('users', [
        'email' => 'github-utm@example.com',
        ...$utms,
        'utm_term' => null,
        'utm_content' => null,
    ]);
});

test('github registration captures the requesting ip address', function () {
    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'gh-ip';
    $socialiteUser->name = 'GitHub IP';
    $socialiteUser->email = 'github-ip@example.com';

    Socialite::shouldReceive('driver')
        ->with('github')
        ->andReturn($driver = Mockery::mock());

    $driver->shouldReceive('scopes')
        ->andReturnSelf();

    $driver->shouldReceive('user')
        ->andReturn($socialiteUser);

    $this->get(route('auth.github.callback'));

    $user = User::where('email', 'github-ip@example.com')->first();

    expect($user->registration_ip)->not->toBeNull();
    expect($user->github_id)->toBe('gh-ip');
});

test('github registration without email redirects to login with error', function () {
    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'gh-no-email';
    $socialiteUser->name = 'No Email';
    $socialiteUser->email = null;

    Socialite::shouldReceive('driver')
        ->with('github')
        ->andReturn($driver = Mockery::mock());

    $driver->shouldReceive('scopes')
        ->andReturnSelf();

    $driver->shouldReceive('user')
        ->andReturn($socialiteUser);

    $this->get(route('auth.github.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('existing github user login consumes the utm session and skips signup success', function () {
    User::factory()->create([
        'email' => 'existing-gh@example.com',
        'github_id' => 'gh-existing',
    ]);

    $this->get(route('auth.github.redirect', ['utm_source' => 'hackernews']));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'gh-existing';
    $socialiteUser->name = 'Existing GitHub';
    $socialiteUser->email = 'existing-gh@example.com';

    Socialite::shouldReceive('driver')
        ->with('github')
        ->andReturn($driver = Mockery::mock());

    $driver->shouldReceive('scopes')
        ->andReturnSelf();

    $driver->shouldReceive('user')
        ->andReturn($socialiteUser);

    $this->get(route('auth.github.callback'))
        ->assertRedirect(route('app.home'));

    expect(session()->get('attribution_parameters'))->toBeNull();
});
