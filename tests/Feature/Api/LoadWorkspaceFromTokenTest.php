<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    config([
        'trypost.self_hosted' => false,
        'trypost.billing.require_card_for_trial' => true,
    ]);

    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->plainToken = $result['plain_token'];
});

test('rejects api requests when the account has no app access', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.workspace.show'))
        ->assertStatus(Response::HTTP_PAYMENT_REQUIRED)
        ->assertJson(['message' => 'Active subscription required.']);
});

test('allows api requests for subscribed accounts', function () {
    subscribeAccount($this->user->account);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.workspace.show'))
        ->assertOk();
});

test('rejects a personal access token after its stored expiration', function () {
    subscribeAccount($this->user->account);

    AccessToken::query()
        ->where('user_id', $this->user->id)
        ->where('workspace_id', $this->workspace->id)
        ->firstOrFail()
        ->forceFill(['expires_at' => now()->subMinute()])
        ->saveQuietly();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.workspace.show'))
        ->assertUnauthorized()
        ->assertJson(['message' => 'Token expired.']);
});

test('allows api requests for generic-trial accounts with app access', function () {
    config(['trypost.billing.require_card_for_trial' => false]);

    $this->user->account->update([
        'trial_ends_at' => now()->addDays(8),
    ]);

    expect($this->user->account->fresh()->hasAppAccess())->toBeTrue()
        ->and($this->user->account->fresh()->subscribed(Account::SUBSCRIPTION_NAME))->toBeFalse();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.workspace.show'))
        ->assertOk();
});

test('allows personal access tokens without a subscription in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    expect($this->user->account->subscribed(Account::SUBSCRIPTION_NAME))->toBeFalse();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.workspace.show'))
        ->assertOk();
});

test('allows scoped mcp oauth without a subscription in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $result = $this->user->createToken('MCP', ['mcp:use']);
    $token = AccessToken::query()->findOrFail($result->token->id);
    DB::table('oauth_clients')
        ->where('id', $token->client_id)
        ->update(['grant_types' => json_encode(['authorization_code'])]);

    $this->withHeaders([
        'Authorization' => "Bearer {$result->accessToken}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson(route('mcp.trypost'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-03-26',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'Pest', 'version' => '1.0'],
        ],
    ])->assertSuccessful();
});

test('rejects a personal token after its owner is demoted from admin', function () {
    subscribeAccount($this->user->account);

    $admin = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($admin->id, ['role' => Role::Admin->value]);
    $admin->update(['current_workspace_id' => $this->workspace->id]);
    $plainToken = passportToken($admin, $this->workspace);

    $this->workspace->members()->updateExistingPivot($admin->id, [
        'role' => Role::Viewer->value,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$plainToken}"])
        ->getJson(route('api.workspace.show'))
        ->assertForbidden();
});

test('rejects a personal token after its owner is removed from the workspace', function () {
    subscribeAccount($this->user->account);

    $admin = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($admin->id, ['role' => Role::Admin->value]);
    $admin->update(['current_workspace_id' => $this->workspace->id]);
    $plainToken = passportToken($admin, $this->workspace);

    $this->workspace->members()->detach($admin->id);

    $this->withHeaders(['Authorization' => "Bearer {$plainToken}"])
        ->getJson(route('api.workspace.show'))
        ->assertForbidden();
});

test('rejects mcp oauth grants for workspace viewers', function () {
    subscribeAccount($this->user->account);

    $viewer = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $this->workspace->id]);
    $result = $viewer->createToken('MCP');
    $token = AccessToken::query()->findOrFail($result->token->id);
    DB::table('oauth_clients')
        ->where('id', $token->client_id)
        ->update(['grant_types' => json_encode(['authorization_code'])]);

    $this->withHeaders(['Authorization' => "Bearer {$result->accessToken}"])
        ->getJson(route('api.workspace.show'))
        ->assertForbidden();
});

test('rejects scoped mcp oauth grants on api routes', function () {
    subscribeAccount($this->user->account);

    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);
    $result = $member->createToken('MCP', ['mcp:use']);
    $token = AccessToken::query()->findOrFail($result->token->id);
    DB::table('oauth_clients')
        ->where('id', $token->client_id)
        ->update(['grant_types' => json_encode(['authorization_code'])]);

    $this->withHeaders(['Authorization' => "Bearer {$result->accessToken}"])
        ->getJson(route('api.workspace.show'))
        ->assertForbidden();
});

test('rejects unscoped mcp oauth grants on api routes', function () {
    subscribeAccount($this->user->account);

    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);
    $result = $member->createToken('MCP');
    $token = AccessToken::query()->findOrFail($result->token->id);
    DB::table('oauth_clients')
        ->where('id', $token->client_id)
        ->update(['grant_types' => json_encode(['authorization_code'])]);

    $this->withHeaders(['Authorization' => "Bearer {$result->accessToken}"])
        ->getJson(route('api.workspace.show'))
        ->assertForbidden();
});

test('rejects personal access tokens on the mcp endpoint after a member becomes a viewer', function () {
    subscribeAccount($this->user->account);

    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $result = $member->createToken('API Key');
    AccessToken::query()->findOrFail($result->token->id)
        ->forceFill(['workspace_id' => $this->workspace->id])
        ->saveQuietly();
    $this->workspace->members()->updateExistingPivot($member->id, [
        'role' => Role::Viewer->value,
    ]);

    $this->withHeaders([
        'Authorization' => "Bearer {$result->accessToken}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson(route('mcp.trypost'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-03-26',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'Pest', 'version' => '1.0'],
        ],
    ])->assertForbidden();
});

test('rejects oauth grants without the mcp scope on the mcp endpoint', function () {
    subscribeAccount($this->user->account);

    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $result = $member->createToken('MCP');
    $token = AccessToken::query()->findOrFail($result->token->id);
    DB::table('oauth_clients')
        ->where('id', $token->client_id)
        ->update(['grant_types' => json_encode(['authorization_code'])]);

    $this->withHeaders([
        'Authorization' => "Bearer {$result->accessToken}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson(route('mcp.trypost'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-03-26',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'Pest', 'version' => '1.0'],
        ],
    ])->assertForbidden();
});

test('allows scoped oauth grants for workspace members on the mcp endpoint', function () {
    subscribeAccount($this->user->account);

    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $result = $member->createToken('MCP', ['mcp:use']);
    $token = AccessToken::query()->findOrFail($result->token->id);
    DB::table('oauth_clients')
        ->where('id', $token->client_id)
        ->update(['grant_types' => json_encode(['authorization_code'])]);

    $this->withHeaders([
        'Authorization' => "Bearer {$result->accessToken}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson(route('mcp.trypost'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-03-26',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'Pest', 'version' => '1.0'],
        ],
    ])->assertSuccessful();
});

test('rejects a revoked personal access token on api routes', function () {
    subscribeAccount($this->user->account);

    AccessToken::query()
        ->where('user_id', $this->user->id)
        ->where('workspace_id', $this->workspace->id)
        ->firstOrFail()
        ->forceFill(['revoked' => true])
        ->saveQuietly();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.workspace.show'))
        ->assertUnauthorized();
});

test('does not treat oauth tokens with a revoked client as personal access tokens', function () {
    $result = $this->user->createToken('MCP', ['mcp:use']);
    $token = AccessToken::query()->findOrFail($result->token->id);
    DB::table('oauth_clients')
        ->where('id', $token->client_id)
        ->update([
            'grant_types' => json_encode(['authorization_code']),
            'revoked' => true,
        ]);

    $token = $token->fresh();

    expect($token->isPersonalAccessToken())->toBeFalse()
        ->and($token->isActiveMcpGrant())->toBeFalse();
});
