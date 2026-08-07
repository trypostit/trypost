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

test('guests are redirected to login before an unknown oauth client is rejected', function () {
    $unknownClientId = (string) Str::uuid();

    $this->get(route('passport.authorizations.authorize', oauthAuthorizeQuery($unknownClientId)))
        ->assertRedirect(route('login'));
});

test('guests are redirected to login before consent for a registered oauth client', function () {
    $clientId = mcpOauthClient('Guest Login Agent');
    DB::table('oauth_clients')->where('id', $clientId)->update([
        'redirect_uris' => json_encode(['https://client.example/callback']),
    ]);

    $this->get(route('passport.authorizations.authorize', oauthAuthorizeQuery($clientId)))
        ->assertRedirect(route('login'));
});

test('authenticated users still receive invalid_client for an unknown oauth client', function () {
    $user = User::factory()->create();
    $unknownClientId = (string) Str::uuid();

    $this->actingAs($user)
        ->getJson(route('passport.authorizations.authorize', oauthAuthorizeQuery($unknownClientId)))
        ->assertUnauthorized()
        ->assertJson([
            'error' => 'invalid_client',
        ]);
});

test('browser requests render an inertia error page for an unknown oauth client', function () {
    $user = User::factory()->create();
    $unknownClientId = (string) Str::uuid();

    $this->actingAs($user)
        ->get(route('passport.authorizations.authorize', oauthAuthorizeQuery($unknownClientId)))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('mcp/AuthorizeError')
            ->where('error', 'invalid_client')
            ->where('errorDescription', 'Client authentication failed'));
});

test('post-login redirect to authorize renders inertia instead of raw oauth json', function () {
    $unknownClientId = (string) Str::uuid();
    $user = User::factory()->create();

    $this->get(route('passport.authorizations.authorize', oauthAuthorizeQuery($unknownClientId)))
        ->assertRedirect(route('login'));

    $login = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $login->assertRedirect();

    $this->get($login->headers->get('Location'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('mcp/AuthorizeError')
            ->where('error', 'invalid_client'));
});

test('prompt=none guests receive login_required redirect instead of an inertia error page', function () {
    $redirectUri = 'https://client.example/callback';
    $clientId = mcpOauthClient('Silent Auth Guest');
    DB::table('oauth_clients')->where('id', $clientId)->update([
        'redirect_uris' => json_encode([$redirectUri]),
    ]);

    $response = $this->get(route(
        'passport.authorizations.authorize',
        oauthAuthorizeQuery($clientId, $redirectUri, prompt: 'none'),
    ));

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toStartWith($redirectUri)
        ->toContain('error=login_required');
});

test('prompt=none authenticated users receive consent_required redirect instead of an inertia error page', function () {
    $user = User::factory()->create();
    $redirectUri = 'https://client.example/callback';
    $clientId = mcpOauthClient('Silent Auth User');
    DB::table('oauth_clients')->where('id', $clientId)->update([
        'redirect_uris' => json_encode([$redirectUri]),
    ]);

    $response = $this->actingAs($user)
        ->get(route(
            'passport.authorizations.authorize',
            oauthAuthorizeQuery($clientId, $redirectUri, prompt: 'none'),
        ));

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toStartWith($redirectUri)
        ->toContain('error=consent_required');
});
