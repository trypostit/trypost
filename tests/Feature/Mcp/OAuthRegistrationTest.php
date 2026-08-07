<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Http\Middleware\App\EnsureCanAuthorizeMcp;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('dynamic oauth client registration is rate limited', function () {
    $payload = [
        'client_name' => 'MCP Client',
        'redirect_uris' => ['https://client.example/callback'],
        'grant_types' => ['authorization_code'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ];

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $this->postJson('/oauth/register', $payload)->assertSuccessful();
    }

    $this->postJson('/oauth/register', $payload)->assertTooManyRequests();
});

test('dynamic oauth registration rejects custom callback schemes', function (string $redirectUri) {
    $this->postJson('/oauth/register', [
        'client_name' => 'Native MCP Client',
        'redirect_uris' => [$redirectUri],
        'grant_types' => ['authorization_code'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ])->assertBadRequest();
})->with([
    'cursor' => 'cursor://oauth/callback',
    'vscode' => 'vscode://oauth/callback',
]);

test('mcp oauth consent page is available for workspace viewers', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $owner->id]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
        'name' => 'Viewer Workspace',
    ]);
    $workspace->members()->attach($owner->id, ['role' => Role::Admin->value]);

    $viewer = User::factory()->create(['account_id' => $account->id]);
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $workspace->id]);

    $clientId = mcpOauthClient('Viewer Agent');
    DB::table('oauth_clients')->where('id', $clientId)->update([
        'redirect_uris' => json_encode(['https://client.example/callback']),
    ]);

    $this->actingAs($viewer)
        ->get(route('passport.authorizations.authorize', oauthAuthorizeQuery($clientId)))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('mcp/Authorize')
            ->where('client.name', 'Viewer Agent')
            ->where('user.email', $viewer->email)
            ->where('selectedWorkspaceId', (string) $workspace->id)
            ->has('workspaces', 1)
            ->where('workspaces.0.id', (string) $workspace->id)
            ->where('workspaces.0.name', 'Viewer Workspace')
            ->has('scopes', 1)
            ->where('scopes.0.id', 'mcp:use')
            ->has('authToken')
            ->where('state', 'test-state'));

    expect(view()->exists('mcp.authorize-denied'))->toBeFalse()
        ->and(class_exists(EnsureCanAuthorizeMcp::class))->toBeFalse();
});

test('mcp oauth consent page uses the active locale', function () {
    app()->setLocale('pt-BR');

    expect(__('mcp.authorize.heading', ['client' => 'Claude']))->toBe('Autorizar Claude')
        ->and(__('mcp.authorize.logged_in_as'))->toBe('Conectado como:')
        ->and(__('mcp.authorize.workspace_scope'))->toBe('Esta conexão terá acesso somente ao workspace selecionado.')
        ->and(__('mcp.authorize.approve'))->toBe('Autorizar')
        ->and(__('mcp.authorize.cancel'))->toBe('Cancelar');
});

test('mcp oauth consent page lists every workspace the user can access', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    $alpha = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
        'name' => 'Alpha',
    ]);
    $beta = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
        'name' => 'Beta',
    ]);
    $alpha->members()->attach($user->id, ['role' => Role::Admin->value]);
    $beta->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $alpha->id]);

    $clientId = mcpOauthClient('Claude');
    DB::table('oauth_clients')->where('id', $clientId)->update([
        'redirect_uris' => json_encode(['https://client.example/callback']),
    ]);

    $this->actingAs($user)
        ->get(route('passport.authorizations.authorize', oauthAuthorizeQuery($clientId)))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('mcp/Authorize')
            ->where('selectedWorkspaceId', (string) $alpha->id)
            ->has('workspaces', 2)
            ->where('workspaces.0.name', 'Alpha')
            ->where('workspaces.1.name', 'Beta')
            ->where('workspaces.0.id', (string) $alpha->id)
            ->where('workspaces.1.id', (string) $beta->id));
});

test('mcp oauth always shows consent even when scopes were previously granted', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $clientId = mcpOauthClient('Reconnect Agent');
    DB::table('oauth_clients')->where('id', $clientId)->update([
        'redirect_uris' => json_encode(['https://client.example/callback']),
    ]);
    mcpAccessToken($user, $clientId, $workspace);

    $query = oauthAuthorizeQuery($clientId);
    unset($query['prompt']);

    $this->actingAs($user)
        ->get(route('passport.authorizations.authorize', $query))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('mcp/Authorize')
            ->where('client.name', 'Reconnect Agent')
            ->where('selectedWorkspaceId', (string) $workspace->id));
});

test('passport approve route has no mcp create-post role gate', function () {
    $route = app('router')->getRoutes()->getByName('passport.authorizations.approve');

    expect($route)->not->toBeNull();

    $middleware = collect($route->gatherMiddleware())
        ->map(fn (mixed $middleware): string => is_string($middleware) ? $middleware : $middleware::class)
        ->implode(',');

    expect($middleware)->not->toContain('EnsureCanAuthorizeMcp');
});

/**
 * @return array<string, string>
 */
function oauthAuthorizeQuery(string $clientId, string $redirectUri = 'https://client.example/callback'): array
{
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'test-state',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'prompt' => 'consent',
    ];
}
