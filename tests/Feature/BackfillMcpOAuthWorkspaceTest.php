<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Load the data migration as an instance so its up() can run against rows that
 * still have unbound MCP OAuth grants.
 */
function backfillMcpOAuthTokenWorkspacesMigration(): object
{
    return require database_path('migrations/2026_08_06_144848_backfill_mcp_oauth_token_workspaces.php');
}

test('backfill revokes mcp oauth tokens when the user has multiple workspaces', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $other = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $other->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $token = mcpAccessToken($user, mcpOauthClient(), workspace: null);

    backfillMcpOAuthTokenWorkspacesMigration()->up();

    expect($token->refresh()->revoked)->toBeTrue()
        ->and($token->refresh()->workspace_id)->toBeNull();
});

test('backfill binds the sole account workspace when current is missing', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => null]);

    $token = mcpAccessToken($user, mcpOauthClient(), workspace: null);

    backfillMcpOAuthTokenWorkspacesMigration()->up();

    expect($token->refresh()->workspace_id)->toBe($workspace->id);
});

test('backfill revokes tokens that cannot be mapped to a workspace', function () {
    $user = User::factory()->create();
    $user->update(['current_workspace_id' => null]);

    $token = mcpAccessToken($user, mcpOauthClient(), workspace: null);

    backfillMcpOAuthTokenWorkspacesMigration()->up();

    expect($token->refresh()->revoked)->toBeTrue()
        ->and($token->refresh()->workspace_id)->toBeNull();
});

test('backfill ignores personal access tokens with null workspace', function () {
    $user = User::factory()->create();
    $result = $user->createToken('PAT');
    $token = $result->token;
    $token->forceFill(['workspace_id' => null])->saveQuietly();

    backfillMcpOAuthTokenWorkspacesMigration()->up();

    expect($token->fresh()->revoked)->toBeFalse()
        ->and($token->fresh()->workspace_id)->toBeNull();
});

test('backfill ignores oauth tokens without the mcp use scope', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $token = mcpAccessToken($user, mcpOauthClient(), workspace: null, scopes: []);

    backfillMcpOAuthTokenWorkspacesMigration()->up();

    expect($token->fresh()->workspace_id)->toBeNull()
        ->and($token->fresh()->revoked)->toBeFalse();
});

test('backfill revokes when multiple workspaces exist without a valid current', function () {
    $user = User::factory()->create();
    $alpha = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $beta = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $alpha->members()->attach($user->id, ['role' => Role::Admin->value]);
    $beta->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => null]);

    $token = mcpAccessToken($user, mcpOauthClient(), workspace: null);

    backfillMcpOAuthTokenWorkspacesMigration()->up();

    expect($token->refresh()->revoked)->toBeTrue()
        ->and($token->refresh()->workspace_id)->toBeNull();
});

test('backfill binds the remaining membership when current workspace was left', function () {
    $user = User::factory()->create();
    $current = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $other = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $other->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $current->id]);

    $token = mcpAccessToken($user, mcpOauthClient(), workspace: null);

    backfillMcpOAuthTokenWorkspacesMigration()->up();

    expect($token->refresh()->workspace_id)->toBe($other->id)
        ->and($token->refresh()->revoked)->toBeFalse();
});

test('backfill leaves dead expired mcp grants untouched', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $token = mcpAccessToken($user, mcpOauthClient(), workspace: null);
    $token->forceFill(['expires_at' => now()->subDay()])->saveQuietly();

    backfillMcpOAuthTokenWorkspacesMigration()->up();

    expect($token->fresh()->workspace_id)->toBeNull()
        ->and($token->fresh()->revoked)->toBeFalse();
});

test('backfill binds expired access tokens that still have a live refresh token', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $token = mcpAccessToken($user, mcpOauthClient(), workspace: null);
    $token->forceFill(['expires_at' => now()->subDay()])->saveQuietly();

    DB::table('oauth_refresh_tokens')->insert([
        'id' => Str::random(80),
        'access_token_id' => $token->id,
        'revoked' => false,
        'expires_at' => now()->addMonth(),
    ]);

    backfillMcpOAuthTokenWorkspacesMigration()->up();

    expect($token->refresh()->workspace_id)->toBe($workspace->id)
        ->and($token->refresh()->revoked)->toBeFalse();
});

test('backfill rolls back binds when the migration fails before commit', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $token = mcpAccessToken($user, mcpOauthClient(), workspace: null);

    $migration = backfillMcpOAuthTokenWorkspacesMigration();
    $migration->beforeCommit = function (): void {
        throw new RuntimeException('forced backfill failure');
    };

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'forced backfill failure');

    expect($token->fresh()->workspace_id)->toBeNull()
        ->and($token->fresh()->revoked)->toBeFalse();
});
