<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config([
        'trypost.self_hosted' => false,
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

// ========================================
// Invite acceptance via OAuth
// ========================================

test('google registration with an invite param completes it instead of creating a default workspace', function () {
    $inviterAccount = Account::factory()->create();
    $inviter = User::factory()->create(['account_id' => $inviterAccount->id]);
    $inviterAccount->update(['owner_id' => $inviter->id]);
    $workspace = Workspace::factory()->create([
        'account_id' => $inviterAccount->id,
        'user_id' => $inviter->id,
    ]);
    $invite = Invite::factory()->create([
        'account_id' => $inviterAccount->id,
        'invited_by' => $inviter->id,
        'email' => 'invited@example.com',
        'workspaces' => [$workspace->id],
    ]);

    $this->get(route('auth.google.redirect', [
        'invite' => $invite->id,
    ]));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => 'g-invited',
        'name' => 'Invited User',
        'email' => 'invited@example.com',
    ]);

    Socialite::shouldReceive('driver')
        ->with('google-auth')
        ->andReturn($driver = Mockery::mock());
    $driver->shouldReceive('user')->andReturn($socialiteUser);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('app.invites.show', $invite, absolute: false));

    $user = User::where('email', 'invited@example.com')->first();
    expect($user)->not->toBeNull();
    // is_invite skipped default workspace creation — CreateUser's own account
    // shell exists, but no personal workspace was spun up under it.
    expect($user->workspaces()->count())->toBe(0);
});

test('google login with a pending invite sends an existing user there instead of app.home', function () {
    $inviterAccount = Account::factory()->create();
    $inviter = User::factory()->create(['account_id' => $inviterAccount->id]);
    $inviterAccount->update(['owner_id' => $inviter->id]);
    $workspace = Workspace::factory()->create([
        'account_id' => $inviterAccount->id,
        'user_id' => $inviter->id,
    ]);
    $invite = Invite::factory()->create([
        'account_id' => $inviterAccount->id,
        'invited_by' => $inviter->id,
        'email' => 'existing-invited@example.com',
        'workspaces' => [$workspace->id],
    ]);

    $existingUser = User::factory()->create(['email' => 'existing-invited@example.com']);

    $this->get(route('auth.google.redirect', [
        'invite' => $invite->id,
    ]));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => 'g-existing-invited',
        'name' => 'Existing Invited',
        'email' => 'existing-invited@example.com',
    ]);

    Socialite::shouldReceive('driver')
        ->with('google-auth')
        ->andReturn($driver = Mockery::mock());
    $driver->shouldReceive('user')->andReturn($socialiteUser);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('app.invites.show', $invite, absolute: false));
    $this->assertAuthenticatedAs($existingUser);
});

// ========================================
// Self-hosted registration gate
// ========================================

test('google registration 404s in self-hosted mode without an invite param', function () {
    config()->set('trypost.self_hosted', true);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => 'g-no-invite',
        'name' => 'No Invite',
        'email' => 'no-invite@example.com',
    ]);

    Socialite::shouldReceive('driver')
        ->with('google-auth')
        ->andReturn($driver = Mockery::mock());
    $driver->shouldReceive('user')->andReturn($socialiteUser);

    $this->get(route('auth.google.callback'))->assertNotFound();

    expect(User::where('email', 'no-invite@example.com')->exists())->toBeFalse();
});

test('google registration succeeds in self-hosted mode with an invite param', function () {
    config()->set('trypost.self_hosted', true);

    $this->get(route('auth.google.redirect', ['invite' => (string) Str::uuid()]));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => 'g-self-hosted-invite',
        'name' => 'Self Hosted Invite',
        'email' => 'self-hosted-invite@example.com',
    ]);

    Socialite::shouldReceive('driver')
        ->with('google-auth')
        ->andReturn($driver = Mockery::mock());
    $driver->shouldReceive('user')->andReturn($socialiteUser);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('register.success'));

    expect(User::where('email', 'self-hosted-invite@example.com')->exists())->toBeTrue();
});

test('google login for an existing user is never blocked by the self-hosted gate', function () {
    config()->set('trypost.self_hosted', true);

    $user = User::factory()->create(['email' => 'existing-self-hosted@example.com']);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => 'g-existing-self-hosted',
        'name' => 'Existing Self Hosted',
        'email' => 'existing-self-hosted@example.com',
    ]);

    Socialite::shouldReceive('driver')
        ->with('google-auth')
        ->andReturn($driver = Mockery::mock());
    $driver->shouldReceive('user')->andReturn($socialiteUser);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('app.home'));
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

    Socialite::shouldReceive('driver')
        ->with('google-auth')
        ->andReturn($driver = Mockery::mock());
    $driver->shouldReceive('user')->andReturn($socialiteUser);

    $this->get(route('auth.google.callback'));

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});
