<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $account = Account::factory()->create();
    $this->user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $this->user->id]);

    $this->workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $this->user->id,
        'name' => 'Owner Workspace',
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    subscribeAccount($account);

    $this->clientId = mcpOauthClient('Auth Token Agent');
    DB::table('oauth_clients')->where('id', $this->clientId)->update([
        'redirect_uris' => json_encode(['https://client.example/callback']),
    ]);
});

/**
 * Happy path: mid-activation owners must get onboardingProgress inline (false), not
 * deferred. Deferred props re-request /oauth/authorize and rotate Passport's authToken
 * while the consent page still holds the old value.
 */
test('mid-activation owner oauth consent shares onboarding progress inline instead of deferring', function () {
    $this->actingAs($this->user)
        ->get(route('passport.authorizations.authorize', oauthAuthorizeQuery($this->clientId)))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('mcp/Authorize')
            // Must be present as false — missing() would mean Inertia::defer() came back.
            ->where('onboardingProgress', false)
            ->has('authToken')
        );
});

test('mid-activation owner can approve oauth consent with the auth token from the consent page', function () {
    $this->actingAs($this->user)
        ->get(route('passport.authorizations.authorize', oauthAuthorizeQuery($this->clientId)))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('mcp/Authorize')
            ->where('onboardingProgress', false)
            ->has('authToken')
        );

    $authToken = session('authToken');

    expect($authToken)->toBeString()->not->toBeEmpty();

    $this->actingAs($this->user)
        ->post(route('passport.authorizations.approve'), [
            'state' => 'test-state',
            'client_id' => $this->clientId,
            'auth_token' => $authToken,
            'workspace_id' => $this->workspace->id,
        ])
        ->assertRedirect();

    expect(session()->has('authToken'))->toBeFalse();
});

test('workspace member can approve oauth consent with the auth token from the consent page', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($member)
        ->get(route('passport.authorizations.authorize', oauthAuthorizeQuery($this->clientId)))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('mcp/Authorize')
            ->where('onboardingProgress', false)
            ->has('authToken')
        );

    $authToken = session('authToken');

    $this->actingAs($member)
        ->post(route('passport.authorizations.approve'), [
            'state' => 'test-state',
            'client_id' => $this->clientId,
            'auth_token' => $authToken,
            'workspace_id' => $this->workspace->id,
        ])
        ->assertRedirect();
});

test('owner cannot approve oauth consent without a workspace', function () {
    $this->actingAs($this->user)
        ->get(route('passport.authorizations.authorize', oauthAuthorizeQuery($this->clientId)))
        ->assertOk();

    $authToken = session('authToken');

    expect($authToken)->toBeString()->not->toBeEmpty();

    $this->actingAs($this->user)
        ->post(route('passport.authorizations.approve'), [
            'state' => 'test-state',
            'client_id' => $this->clientId,
            'auth_token' => $authToken,
            'workspace_id' => '',
        ])
        ->assertStatus(Response::HTTP_BAD_REQUEST);

    expect(DB::table('oauth_auth_codes')->where('client_id', $this->clientId)->exists())->toBeFalse();
});

/**
 * Problem path: a second hit to /oauth/authorize (what Inertia deferred props used to
 * do for owners) rotates authToken. Approving with the token still rendered on the
 * page then fails with InvalidAuthTokenException.
 */
test('a deferred-style second authorize visit rotates auth token and rejects the stale one', function () {
    $query = oauthAuthorizeQuery($this->clientId);

    $this->actingAs($this->user)
        ->get(route('passport.authorizations.authorize', $query))
        ->assertOk();

    $staleToken = session('authToken');

    expect($staleToken)->toBeString()->not->toBeEmpty();

    // Same authorize URL again — Inertia deferred props used to trigger this for owners.
    $this->actingAs($this->user)
        ->get(route('passport.authorizations.authorize', $query))
        ->assertOk();

    expect(session('authToken'))->not->toBe($staleToken);

    $this->actingAs($this->user)
        ->post(route('passport.authorizations.approve'), [
            'state' => 'test-state',
            'client_id' => $this->clientId,
            'auth_token' => $staleToken,
            'workspace_id' => $this->workspace->id,
        ])
        ->assertForbidden();
});
