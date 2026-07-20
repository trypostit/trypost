<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config([
        'services.google-auth.client_id' => 'test-client-id',
        'services.google-auth.client_secret' => 'test-client-secret',
        'services.google-auth.redirect' => 'https://app.trypost.test/auth/google/callback',
    ]);
});

test('google redirect returns redirect response', function () {
    $response = $this->get(route('auth.google.redirect'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('accounts.google.com');
});

test('google callback logs in existing user by email', function () {
    $user = User::factory()->create([
        'email' => 'existing@example.com',
    ]);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => '123456',
        'name' => 'Existing User',
        'email' => 'existing@example.com',
    ]);

    // Google's OIDC userinfo confirms the address; the callback only trusts
    // an email the provider itself has verified.
    $socialiteUser->user = ['email_verified' => true];

    Socialite::shouldReceive('driver')
        ->with('google-auth')
        ->andReturn($driver = Mockery::mock());
    $driver->shouldReceive('user')->andReturn($socialiteUser);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('app.home'));
    $this->assertAuthenticatedAs($user);
});

test('google callback creates new user when email does not exist', function () {
    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => '789',
        'name' => 'New User',
        'email' => 'new@example.com',
    ]);

    // Google's OIDC userinfo confirms the address; the callback only trusts
    // an email the provider itself has verified.
    $socialiteUser->user = ['email_verified' => true];

    Socialite::shouldReceive('driver')
        ->with('google-auth')
        ->andReturn($driver = Mockery::mock());
    $driver->shouldReceive('user')->andReturn($socialiteUser);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('register.success'));

    $user = User::where('email', 'new@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('New User');
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->account_id)->not->toBeNull();
    expect($user->workspaces()->count())->toBe(1);
    expect($user->workspaces()->first()->name)->toBe("New User's Workspace");
    expect($user->current_workspace_id)->toBe($user->workspaces()->first()->id);
    $this->assertAuthenticatedAs($user);
});

test('github callback creates new user with a default workspace', function () {
    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => '987',
        'name' => 'New Dev',
        'email' => 'newdev@example.com',
    ]);

    // GitHub exposes verification through the emails API, which the callback
    // checks before trusting the address.
    $socialiteUser->token = 'gh-token';
    Http::fake([
        config('services.github.api').'/user/emails' => Http::response([
            ['email' => 'newdev@example.com', 'verified' => true, 'primary' => true],
        ]),
    ]);

    Socialite::shouldReceive('driver')
        ->with('github')
        ->andReturn($driver = Mockery::mock());
    $driver->shouldReceive('user')->andReturn($socialiteUser);

    $response = $this->get(route('auth.github.callback'));

    $response->assertRedirect(route('register.success'));

    $user = User::where('email', 'newdev@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->github_id)->toBe('987');
    expect($user->name)->toBe('New Dev');
    expect($user->workspaces()->count())->toBe(1);
    expect($user->workspaces()->first()->name)->toBe("New Dev's Workspace");
    expect($user->current_workspace_id)->toBe($user->workspaces()->first()->id);
    $this->assertAuthenticatedAs($user);
});

test('google callback marks unverified existing user as verified', function () {
    $user = User::factory()->create([
        'email' => 'unverified@example.com',
        'email_verified_at' => null,
    ]);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => '456',
        'name' => 'Unverified User',
        'email' => 'unverified@example.com',
    ]);

    // Google's OIDC userinfo confirms the address; the callback only trusts
    // an email the provider itself has verified.
    $socialiteUser->user = ['email_verified' => true];

    Socialite::shouldReceive('driver')
        ->with('google-auth')
        ->andReturn($driver = Mockery::mock());
    $driver->shouldReceive('user')->andReturn($socialiteUser);

    $this->get(route('auth.google.callback'));

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});
