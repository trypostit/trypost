<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Passport\AuthorizationView;
use Illuminate\Http\Request;
use Inertia\Support\Header;
use Laravel\Passport\Scope;

test('authorization view prefers the current workspace and maps consent props', function () {
    $user = User::factory()->create();
    $alpha = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
        'name' => 'Alpha',
    ]);
    $beta = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
        'name' => 'Beta',
    ]);
    $alpha->members()->attach($user->id, ['role' => Role::Admin->value]);
    $beta->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $beta->id]);

    $props = authorizationViewProps($user, state: 'consent-state');

    expect($props)
        ->client->id->toBe('client-1')
        ->client->name->toBe('Claude')
        ->user->email->toBe($user->email)
        ->selectedWorkspaceId->toBe($beta->id)
        ->workspaces->toHaveCount(2)
        ->workspaces->{0}->name->toBe('Alpha')
        ->workspaces->{1}->name->toBe('Beta')
        ->scopes->{0}->id->toBe('mcp:use')
        ->authToken->toBe('auth-token')
        ->state->toBe('consent-state');
});

test('authorization view falls back to the first workspace when current is unavailable', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
        'name' => 'Only',
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => null]);

    $props = authorizationViewProps($user);

    expect($props['selectedWorkspaceId'])->toBe($workspace->id)
        ->and($props['workspaces'])->toHaveCount(1);
});

test('authorization view returns empty workspace props without a user', function () {
    $props = authorizationViewProps(user: null);

    expect($props['workspaces'])->toBe([])
        ->and($props['selectedWorkspaceId'])->toBe('')
        ->and($props['user']['email'])->toBeNull();
});

/**
 * @return array<string, mixed>
 */
function authorizationViewProps(?User $user, string $state = ''): array
{
    $request = Request::create('/oauth/authorize', 'GET', ['state' => $state]);
    $request->headers->set(Header::INERTIA, 'true');

    $response = app(AuthorizationView::class)([
        'client' => (object) ['id' => 'client-1', 'name' => 'Claude'],
        'user' => $user,
        'scopes' => [new Scope('mcp:use', 'Use MCP server')],
        'authToken' => 'auth-token',
        'request' => $request,
    ])->toResponse($request);

    return $response->getData(true)['props'];
}
