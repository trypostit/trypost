<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('login page exposes selfHosted as false when SELF_HOSTED is off', function () {
    config()->set('trypost.self_hosted', false);

    $response = $this->get(route('login'));

    $response->assertOk();
    $page = $response->original->getData()['page'];
    expect($page['props']['selfHosted'])->toBeFalse();
});

test('login page exposes selfHosted as true when SELF_HOSTED is on', function () {
    config()->set('trypost.self_hosted', true);

    $response = $this->get(route('login'));

    $response->assertOk();
    $page = $response->original->getData()['page'];
    expect($page['props']['selfHosted'])->toBeTrue();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('app.calendar', absolute: false));
});

test('login with a valid invite param redirects to the invite page instead of the calendar', function () {
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
        'email' => 'invited-login@example.com',
        'workspaces' => [$workspace->id],
    ]);
    $user = User::factory()->create(['email' => 'invited-login@example.com']);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'invite' => $invite->id,
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('app.invites.show', $invite, absolute: false));
});

test('login with an unknown invite param falls back to the calendar redirect', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'invite' => (string) Str::uuid(),
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('app.calendar', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect('/');
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    $throttleKey = Str::transliterate(Str::lower($user->email).'|127.0.0.1');

    RateLimiter::hit($throttleKey, 60);
    RateLimiter::hit($throttleKey, 60);
    RateLimiter::hit($throttleKey, 60);
    RateLimiter::hit($throttleKey, 60);
    RateLimiter::hit($throttleKey, 60);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors('email');
});
