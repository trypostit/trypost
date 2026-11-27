<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;

beforeEach(fn () => config()->set('trypost.self_hosted', false));

test('login page loads when google auth is disabled', function () {
    config(['trypost.google_auth_enabled' => false]);

    $response = $this->get(route('login'));

    $response->assertOk();
});

test('login page loads when google auth is enabled', function () {
    config(['trypost.google_auth_enabled' => true]);

    $response = $this->get(route('login'));

    $response->assertOk();
});

test('register page loads when google auth is disabled', function () {
    config(['trypost.google_auth_enabled' => false]);

    $response = $this->get(route('register'));

    $response->assertOk();
});

test('register page loads when google auth is enabled', function () {
    config(['trypost.google_auth_enabled' => true]);

    $response = $this->get(route('register'));

    $response->assertOk();
});

test('login page shares google auth enabled prop as false when disabled', function () {
    config(['trypost.google_auth_enabled' => false]);

    $response = $this->get(route('login'));

    $response->assertOk();

    $page = $response->original->getData()['page'];
    expect($page['props']['googleAuthEnabled'])->toBeFalse();
});

test('login page shares google auth enabled prop as true when enabled', function () {
    config(['trypost.google_auth_enabled' => true]);

    $response = $this->get(route('login'));

    $response->assertOk();

    $page = $response->original->getData()['page'];
    expect($page['props']['googleAuthEnabled'])->toBeTrue();
});

test('google auth redirect route exists', function () {
    config(['trypost.google_auth_enabled' => true]);

    $response = $this->get(route('auth.google.redirect'));

    // Should redirect to Google OAuth, not 404
    $response->assertRedirect();
});

test('google auth redirect route 404s when google auth is disabled', function () {
    config(['trypost.google_auth_enabled' => false]);

    $this->get(route('auth.google.redirect'))->assertNotFound();
});

test('google auth callback route exists', function () {
    $response = $this->get(route('auth.google.callback'));

    // Should redirect to login on failure (no OAuth code), not 404
    $response->assertRedirect(route('login'));
});

test('register page still shares google auth enabled prop when self_hosted (via pending invite)', function () {
    config()->set('trypost.self_hosted', true);
    config()->set('trypost.google_auth_enabled', true);

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
        'workspaces' => [$workspace->id],
    ]);

    $response = $this
        ->withSession(['pending_invite_id' => $invite->id])
        ->get(route('register'));

    $response->assertOk();
    $page = $response->original->getData()['page'];
    expect($page['props']['googleAuthEnabled'])->toBeTrue();
});
