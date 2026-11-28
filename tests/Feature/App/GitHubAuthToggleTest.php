<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;

beforeEach(fn () => config()->set('trypost.self_hosted', false));

test('login page shares github auth enabled prop as false when disabled', function () {
    config(['trypost.github_auth_enabled' => false]);

    $response = $this->get(route('login'));

    $response->assertOk();

    $page = $response->original->getData()['page'];
    expect($page['props']['githubAuthEnabled'])->toBeFalse();
});

test('login page shares github auth enabled prop as true when enabled', function () {
    config(['trypost.github_auth_enabled' => true]);

    $response = $this->get(route('login'));

    $response->assertOk();

    $page = $response->original->getData()['page'];
    expect($page['props']['githubAuthEnabled'])->toBeTrue();
});

test('register page shares github auth enabled prop', function () {
    config(['trypost.github_auth_enabled' => true]);

    $response = $this->get(route('register'));

    $response->assertOk();

    $page = $response->original->getData()['page'];
    expect($page['props']['githubAuthEnabled'])->toBeTrue();
});

test('github auth redirect route exists', function () {
    config(['trypost.github_auth_enabled' => true]);
    config(['services.github.client_id' => 'test-id']);
    config(['services.github.client_secret' => 'test-secret']);
    config(['services.github.redirect' => 'https://app.trypost.test/auth/github/callback']);

    $response = $this->get(route('auth.github.redirect'));

    // Should redirect to GitHub OAuth, not 404
    $response->assertRedirect();
});

test('github auth redirect route 404s when github auth is disabled', function () {
    config(['trypost.github_auth_enabled' => false]);

    $this->get(route('auth.github.redirect'))->assertNotFound();
});

test('github auth callback route exists', function () {
    $response = $this->get(route('auth.github.callback'));

    // Should redirect to login on failure (no OAuth code), not 404
    $response->assertRedirect(route('login'));
});

test('register page still shares github auth enabled prop when self_hosted (via pending invite)', function () {
    config()->set('trypost.self_hosted', true);
    config()->set('trypost.github_auth_enabled', true);

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
    expect($page['props']['githubAuthEnabled'])->toBeTrue();
});
