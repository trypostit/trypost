<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;

beforeEach(fn () => config()->set('trypost.self_hosted', false));

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'Password123!',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();
    $response->assertRedirect(route('register.success', absolute: false));
});

test('new users get a default workspace on registration', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'Password123!',
    ]);

    $user = User::where('email', 'test@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->account_id)->not->toBeNull();
    expect($user->workspaces()->count())->toBe(1);
    expect($user->workspaces()->first()->name)->toBe("Test User's Workspace");
    expect($user->current_workspace_id)->toBe($user->workspaces()->first()->id);
});

test('new users do not have verified email by default', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'Password123!',
    ]);

    $user = User::where('email', 'test@example.com')->first();

    expect($user->email_verified_at)->toBeNull();
});

test('new users registering via invite have verified email automatically', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $owner->id]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);
    $invite = Invite::factory()->create([
        'account_id' => $account->id,
        'invited_by' => $owner->id,
        'email' => 'test@example.com',
        'workspaces' => [$workspace->id],
    ]);

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'Password123!',
        'invite' => $invite->id,
        'redirect' => route('app.invites.show', $invite),
    ]);

    $user = User::where('email', 'test@example.com')->first();

    expect($user->email_verified_at)->not->toBeNull();
});

test('register page returns 404 when self_hosted and no pending invite in session', function () {
    config()->set('trypost.self_hosted', true);

    $response = $this->get(route('register'));

    $response->assertNotFound();
});

test('register POST returns 404 when self_hosted and no pending invite in session', function () {
    config()->set('trypost.self_hosted', true);

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'Password123!',
    ]);

    $response->assertNotFound();
    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('register page renders when self_hosted but session has pending invite', function () {
    config()->set('trypost.self_hosted', true);

    $response = $this
        ->withSession(['pending_invite_id' => 'invite-abc'])
        ->get(route('register'));

    $response->assertOk();
});

test('register page renders when self_hosted with invite query param and persists it to session', function () {
    config()->set('trypost.self_hosted', true);

    $response = $this->get(route('register', ['invite' => 'invite-xyz']));

    $response->assertOk();
    $response->assertSessionHas('pending_invite_id', 'invite-xyz');
});

test('signup clears pending_invite_id from session', function () {
    config()->set('trypost.self_hosted', true);

    $this->withSession(['pending_invite_id' => 'invite-abc'])
        ->post(route('register.store'), [
            'name' => 'Invitee',
            'email' => 'invitee@example.com',
            'password' => 'Password123!',
        ]);

    expect(session('pending_invite_id'))->toBeNull();
    expect(User::where('email', 'invitee@example.com')->exists())->toBeTrue();
});

test('register POST passes when self_hosted with invite query param even without prior session', function () {
    config()->set('trypost.self_hosted', true);

    $response = $this->post(route('register.store', ['invite' => 'invite-xyz']), [
        'name' => 'Invitee',
        'email' => 'invitee@example.com',
        'password' => 'Password123!',
    ]);

    $response->assertSessionHasNoErrors();
    expect(User::where('email', 'invitee@example.com')->exists())->toBeTrue();
});

test('register works normally when not self_hosted even with pending invite in session', function () {
    config()->set('trypost.self_hosted', false);

    $response = $this
        ->withSession(['pending_invite_id' => 'invite-abc'])
        ->post(route('register.store'), [
            'name' => 'Invitee',
            'email' => 'invitee@example.com',
            'password' => 'Password123!',
        ]);

    $response->assertSessionHasNoErrors();
    expect(User::where('email', 'invitee@example.com')->exists())->toBeTrue();
    // Cleared regardless of mode, since signup consumes the marker.
    expect(session('pending_invite_id'))->toBeNull();
});
