<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
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

    $issued = mcpBearerToken($this->user, $this->workspace);

    $this->withHeaders([
        'Authorization' => "Bearer {$issued['plain_token']}",
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
        ->assertForbidden()
        ->assertJson(['message' => 'Insufficient workspace permissions.']);
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
        ->assertForbidden()
        ->assertJson(['message' => 'Workspace access denied.']);
});

test('rejects mcp oauth grants on api routes for workspace viewers', function () {
    subscribeAccount($this->user->account);

    $viewer = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $this->workspace->id]);
    $issued = mcpBearerToken($viewer, $this->workspace);

    $this->withHeaders(['Authorization' => "Bearer {$issued['plain_token']}"])
        ->getJson(route('api.workspace.show'))
        ->assertForbidden()
        ->assertJson(['message' => 'Personal access token required.']);
});

test('rejects scoped mcp oauth grants on api routes', function () {
    subscribeAccount($this->user->account);

    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);
    $issued = mcpBearerToken($member, $this->workspace);

    $this->withHeaders(['Authorization' => "Bearer {$issued['plain_token']}"])
        ->getJson(route('api.workspace.show'))
        ->assertForbidden()
        ->assertJson(['message' => 'Personal access token required.']);
});

test('rejects unscoped mcp oauth grants on api routes', function () {
    subscribeAccount($this->user->account);

    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);
    $issued = mcpBearerToken($member, $this->workspace, scopes: []);

    $this->withHeaders(['Authorization' => "Bearer {$issued['plain_token']}"])
        ->getJson(route('api.workspace.show'))
        ->assertForbidden()
        ->assertJson(['message' => 'Personal access token required.']);
});

test('rejects personal access tokens on the mcp endpoint', function () {
    subscribeAccount($this->user->account);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
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
    ])
        ->assertForbidden()
        ->assertJson(['message' => 'MCP OAuth authorization required.']);
});

test('rejects oauth grants without the mcp scope on the mcp endpoint', function () {
    subscribeAccount($this->user->account);

    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $issued = mcpBearerToken($member, $this->workspace, scopes: []);

    $this->withHeaders([
        'Authorization' => "Bearer {$issued['plain_token']}",
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

    $issued = mcpBearerToken($member, $this->workspace);

    $this->withHeaders([
        'Authorization' => "Bearer {$issued['plain_token']}",
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

test('allows scoped oauth grants for workspace viewers on the mcp endpoint', function () {
    subscribeAccount($this->user->account);

    $viewer = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $this->workspace->id]);

    $issued = mcpBearerToken($viewer, $this->workspace);

    $this->withHeaders([
        'Authorization' => "Bearer {$issued['plain_token']}",
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
    $issued = mcpBearerToken($this->user, $this->workspace);
    $token = $issued['token'];
    DB::table('oauth_clients')
        ->where('id', $token->client_id)
        ->update(['revoked' => true]);

    $token = $token->fresh();

    expect($token->isPersonalAccessToken())->toBeFalse()
        ->and($token->isActiveMcpGrant())->toBeFalse();
});

test('rejects api requests without a bearer token', function () {
    $this->getJson(route('api.workspace.show'))
        ->assertUnauthorized();
});

test('rejects mcp oauth without a bound workspace', function () {
    subscribeAccount($this->user->account);

    $issued = mcpBearerToken($this->user, $this->workspace);
    $issued['token']->forceFill(['workspace_id' => null])->saveQuietly();

    $this->withHeaders([
        'Authorization' => "Bearer {$issued['plain_token']}",
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
    ])
        ->assertUnauthorized()
        ->assertJson(['message' => 'No workspace selected.']);
});

test('mcp oauth uses its bound workspace even when the user switched current workspace', function () {
    subscribeAccount($this->user->account);

    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $otherWorkspace->members()->attach($this->user->id, ['role' => Role::Member->value]);

    $issued = mcpBearerToken($this->user, $this->workspace);

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-03-26',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'Pest', 'version' => '1.0'],
        ],
    ];

    $this->user->update(['current_workspace_id' => $otherWorkspace->id]);

    $this->withHeaders([
        'Authorization' => "Bearer {$issued['plain_token']}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson(route('mcp.trypost'), $payload)->assertSuccessful();

    expect($this->user->fresh()->current_workspace_id)->toBe($otherWorkspace->id);
    expect($issued['token']->fresh()->workspace_id)->toBe($this->workspace->id);
});

test('same mcp client can stay connected to two workspaces independently', function () {
    subscribeAccount($this->user->account);

    $workspaceB = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $workspaceB->members()->attach($this->user->id, ['role' => Role::Admin->value]);

    $clientId = mcpOauthClient('Claude');
    $onA = mcpBearerToken($this->user, $this->workspace);
    $onB = mcpBearerToken($this->user, $workspaceB);

    $onA['token']->forceFill(['client_id' => $clientId])->saveQuietly();
    $onB['token']->forceFill(['client_id' => $clientId])->saveQuietly();

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-03-26',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'Pest', 'version' => '1.0'],
        ],
    ];

    $this->withHeaders([
        'Authorization' => "Bearer {$onA['plain_token']}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson(route('mcp.trypost'), $payload)->assertSuccessful();

    $this->withHeaders([
        'Authorization' => "Bearer {$onB['plain_token']}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson(route('mcp.trypost'), $payload)->assertSuccessful();

    expect($onA['token']->fresh()->revoked)->toBeFalse()
        ->and($onA['token']->fresh()->workspace_id)->toBe($this->workspace->id)
        ->and($onB['token']->fresh()->revoked)->toBeFalse()
        ->and($onB['token']->fresh()->workspace_id)->toBe($workspaceB->id);
});

test('rejects mcp oauth bound to a workspace the user no longer belongs to', function () {
    subscribeAccount($this->user->account);

    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $issued = mcpBearerToken($member, $this->workspace);
    $this->workspace->members()->detach($member->id);

    $this->withHeaders([
        'Authorization' => "Bearer {$issued['plain_token']}",
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
    ])
        ->assertForbidden()
        ->assertJson(['message' => 'Workspace access denied.']);
});

test('personal access token uses its bound workspace even when the user switched current workspace', function () {
    subscribeAccount($this->user->account);

    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
        'name' => 'Workspace B',
    ]);
    $otherWorkspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);

    $this->user->update(['current_workspace_id' => $otherWorkspace->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.workspace.show'))
        ->assertOk()
        ->assertJsonPath('id', $this->workspace->id);
});

test('records last_used_at on the access token after a successful api request', function () {
    subscribeAccount($this->user->account);

    $token = AccessToken::query()
        ->where('user_id', $this->user->id)
        ->where('workspace_id', $this->workspace->id)
        ->firstOrFail();

    expect($token->last_used_at)->toBeNull();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.workspace.show'))
        ->assertOk();

    expect($token->fresh()->last_used_at)->not->toBeNull();
});

test('rejects an expired mcp oauth grant on the mcp endpoint', function () {
    subscribeAccount($this->user->account);

    $issued = mcpBearerToken($this->user, $this->workspace);
    $issued['token']->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();

    $this->withHeaders([
        'Authorization' => "Bearer {$issued['plain_token']}",
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
    ])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Token expired.']);
});
