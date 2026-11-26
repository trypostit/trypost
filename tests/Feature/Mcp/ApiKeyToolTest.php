<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
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
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
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
            $json->where('current_page', 1)->where('per_page', (int) config('app.pagination.default'))->etc()->has('api_keys', 2, function (AssertableJson $key) {
                $key->hasAll(['id', 'name', 'last_used_at', 'expires_at', 'created_at'])
                    ->missing('token')
                    ->missing('user_id')
                    ->missing('workspace_id')
                    ->missing('client_id');
            });
        });
});

test('list api keys excludes workspace-bound MCP OAuth grants', function () {
    attachToken($this->user, $this->workspace);

    mcpAccessToken($this->user, mcpOauthClient('ChatGPT'), $this->workspace);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListApiKeysTool::class, []);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('api_keys', 1)->etc();
        });
});

test('workspace members cannot manage api keys through mcp', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));
    $member->update(['current_workspace_id' => $this->workspace->id]);
    $token = attachToken($member, $this->workspace);

    TryPostServer::actingAs($member)
        ->tool(ListApiKeysTool::class, [])
        ->assertHasErrors();

    TryPostServer::actingAs($member)
        ->tool(DeleteApiKeyTool::class, ['api_key_id' => $token->id])
        ->assertHasErrors();

    expect($token->fresh()->revoked)->toBeFalse()
        ->and(AccessToken::where('user_id', $member->id)->count())->toBe(1);
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

test('cannot delete workspace-bound MCP OAuth through the api key tool', function () {
    $oauthToken = mcpAccessToken($this->user, mcpOauthClient('ChatGPT'), $this->workspace);

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

test('the MCP server no longer registers a create api key tool', function () {
    $tools = (new ReflectionClass(TryPostServer::class))->getDefaultProperties()['tools'] ?? [];

    expect($tools)->not->toBeEmpty()
        ->and(collect($tools)->map(fn (string $tool): string => class_basename($tool)))
        ->not->toContain('CreateApiKeyTool');
});
