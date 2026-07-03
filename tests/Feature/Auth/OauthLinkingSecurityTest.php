<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

function fakeGoogleUser(string $email, bool $verified): SocialiteUser
{
    $socialUser = new SocialiteUser;
    $socialUser->map([
        'id' => 'google-123',
        'name' => 'OAuth User',
        'email' => $email,
    ]);
    $socialUser->user = [
        'sub' => 'google-123',
        'email' => $email,
        'email_verified' => $verified,
    ];

    return $socialUser;
}

function mockGoogleCallback(SocialiteUser $socialUser): void
{
    $driver = Mockery::mock(AbstractProvider::class);
    $driver->shouldReceive('user')->andReturn($socialUser);
    Socialite::shouldReceive('driver')->with('google-auth')->andReturn($driver);
}

test('google login with unverified provider email cannot link an existing account', function () {
    $victim = User::factory()->create(['email' => 'victim@example.com']);

    mockGoogleCallback(fakeGoogleUser('victim@example.com', verified: false));

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect($victim->fresh()->google_id)->toBeNull();
});

test('google login with verified provider email links the matching account', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);

    mockGoogleCallback(fakeGoogleUser('owner@example.com', verified: true));

    $this->get(route('auth.google.callback'));

    $this->assertAuthenticatedAs($user->fresh());
    expect($user->fresh()->google_id)->toBe('google-123');
});

test('an already linked google account logs in regardless of the claim', function () {
    $user = User::factory()->create(['google_id' => 'google-123']);

    mockGoogleCallback(fakeGoogleUser($user->email, verified: false));

    $this->get(route('auth.google.callback'));

    $this->assertAuthenticatedAs($user->fresh());
});
