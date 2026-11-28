<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config([
        'trypost.self_hosted' => false,
        'trypost.google_auth_enabled' => true,
        'services.google-auth.client_id' => 'test-client-id',
        'services.google-auth.client_secret' => 'test-client-secret',
        'services.google-auth.redirect' => 'https://app.trypost.test/auth/google/callback',
    ]);
});

test('a google login whose email is not the invited one is sent to login with the wrong email error', function () {
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
        'email' => 'intended-recipient@example.com',
        'workspaces' => [$workspace->id],
    ]);

    $this->get(route('auth.google.redirect', ['invite' => $invite->id]));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => 'g-wrong-email',
        'name' => 'Someone Else',
        'email' => 'someone-else@example.com',
    ]);

    Socialite::shouldReceive('driver')
        ->with('google-auth')
        ->andReturn($driver = Mockery::mock());
    $driver->shouldReceive('user')->andReturn($socialiteUser);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('settings.members.flash.wrong_email')]);

    $this->assertGuest();
    expect(User::where('email', 'someone-else@example.com')->exists())->toBeFalse();
});
