<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\ApiKey\CreateApiKeyTool;
use App\Mcp\Tools\ApiKey\DeleteApiKeyTool;
use App\Mcp\Tools\ApiKey\ListApiKeysTool;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();
});

function attachToken(User $user, Workspace $workspace): AccessToken
{
    $result = $user->createToken('Existing');
    $token = AccessToken::find($result->token->id);
    $token->forceFill(['workspace_id' => $workspace->id])->saveQuietly();

    return $token->refresh();
}

test('list api keys returns wrapped api_keys array with ApiKeyResource shape', function () {
    attachToken($this->user, $this->workspace);
    attachToken($this->user, $this->workspace);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListApiKeysTool::class, []);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('api_keys', 2, function (AssertableJson $key) {
                $key->hasAll(['id', 'name', 'last_used_at', 'expires_at', 'created_at'])
                    ->missing('token')
                    ->missing('user_id')
                    ->missing('workspace_id')
                    ->missing('client_id');
            });
        });
});

test('list api keys excludes OAuth tokens (workspace_id null)', function () {
    // Personal Access Token (workspace bound)
    attachToken($this->user, $this->workspace);

    // OAuth-flow token (workspace_id null — like ChatGPT MCP session)
    $oauthResult = $this->user->createToken('OAuth Session');
    AccessToken::find($oauthResult->token->id)
        ->forceFill(['workspace_id' => null])
        ->saveQuietly();

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListApiKeysTool::class, []);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('api_keys', 1)->etc();
        });
});

test('create api key returns plain token only at creation', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreateApiKeyTool::class, ['name' => 'My Key']);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('name', 'My Key')
                ->has('token')
                ->hasAll(['id', 'last_used_at', 'expires_at', 'created_at'])
                ->etc();
        });

    expect(AccessToken::where('user_id', $this->user->id)
        ->where('workspace_id', $this->workspace->id)
        ->count())->toBe(1);
});

test('workspace members cannot manage api keys through mcp', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);
    $token = attachToken($member, $this->workspace);

    TryPostServer::actingAs($member)
        ->tool(ListApiKeysTool::class, [])
        ->assertHasErrors();

    TryPostServer::actingAs($member)
        ->tool(CreateApiKeyTool::class, ['name' => 'Escalation Key'])
        ->assertHasErrors();

    TryPostServer::actingAs($member)
        ->tool(DeleteApiKeyTool::class, ['api_key_id' => $token->id])
        ->assertHasErrors();

    expect($token->fresh()->revoked)->toBeFalse()
        ->and(AccessToken::where('user_id', $member->id)->count())->toBe(1);
});

test('create api key validates name required', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreateApiKeyTool::class, []);

    $response->assertHasErrors();
});

test('create api key rejects expires_at in the past', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreateApiKeyTool::class, [
            'name' => 'Past Key',
            'expires_at' => '2020-01-01',
        ]);

    $response->assertHasErrors();
});

test('create api key omits expiration when not provided', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreateApiKeyTool::class, [
            'name' => 'Never Expires',
        ]);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('name', 'Never Expires')
                ->where('expires_at', null)
                ->etc();
        });

    expect(AccessToken::query()
        ->where('user_id', $this->user->id)
        ->where('workspace_id', $this->workspace->id)
        ->where('name', 'Never Expires')
        ->firstOrFail()
        ->expires_at)->toBeNull();
});

test('create api key stores expiration at end of day', function () {
    $expiresAt = now()->addDays(14)->startOfDay();

    $response = TryPostServer::actingAs($this->user)
        ->tool(CreateApiKeyTool::class, [
            'name' => 'Expiring Key',
            'expires_at' => $expiresAt->toDateString(),
        ]);

    $response->assertOk();

    $token = AccessToken::query()
        ->where('user_id', $this->user->id)
        ->where('workspace_id', $this->workspace->id)
        ->where('name', 'Expiring Key')
        ->firstOrFail();

    expect($token->expires_at->toDateString())->toBe($expiresAt->toDateString())
        ->and($token->expires_at->format('H:i:s'))->toBe('23:59:59');
});

test('create api key allows an expiration of today', function () {
    $today = now()->toDateString();

    $response = TryPostServer::actingAs($this->user)
        ->tool(CreateApiKeyTool::class, [
            'name' => 'Expires Today',
            'expires_at' => $today,
        ]);

    $response->assertOk();

    $token = AccessToken::query()
        ->where('user_id', $this->user->id)
        ->where('workspace_id', $this->workspace->id)
        ->where('name', 'Expires Today')
        ->firstOrFail();

    expect($token->expires_at->toDateString())->toBe($today)
        ->and($token->expires_at->format('H:i:s'))->toBe('23:59:59');
});

test('delete api key marks revoked', function () {
    $token = attachToken($this->user, $this->workspace);

    $response = TryPostServer::actingAs($this->user)
        ->tool(DeleteApiKeyTool::class, ['api_key_id' => $token->id]);

    $response->assertOk()
        ->assertStructuredContent(['deleted' => true]);

    expect($token->refresh()->revoked)->toBeTrue();
});

test('cannot delete api key from another user', function () {
    $otherUser = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $otherUser->account_id,
        'user_id' => $otherUser->id,
    ]);
    $token = attachToken($otherUser, $otherWorkspace);

    $response = TryPostServer::actingAs($this->user)
        ->tool(DeleteApiKeyTool::class, ['api_key_id' => $token->id]);

    $response->assertHasErrors(['API key not found.']);
});

test('cannot delete OAuth-flow token through this tool', function () {
    // OAuth token has workspace_id null — DeleteApiKeyTool filter excludes it
    $oauthResult = $this->user->createToken('OAuth Session');
    $oauthToken = AccessToken::find($oauthResult->token->id);
    $oauthToken->forceFill(['workspace_id' => null])->saveQuietly();

    $response = TryPostServer::actingAs($this->user)
        ->tool(DeleteApiKeyTool::class, ['api_key_id' => $oauthToken->id]);

    $response->assertHasErrors(['API key not found.']);
    expect($oauthToken->refresh()->revoked)->toBeFalse();
});

test('delete api key validates api_key_id required', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(DeleteApiKeyTool::class, []);

    $response->assertHasErrors();
});
