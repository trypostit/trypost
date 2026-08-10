<?php

declare(strict_types=1);

namespace App\Actions\AccessToken;

use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class RevokeMcpOAuthGrants
{
    /**
     * Revoke MCP OAuth grants only when the user can no longer view any
     * workspace (full removal). Demotion to Viewer keeps the grant — write
     * tools enforce createPost via policies, matching the web app.
     *
     * @return bool True when at least one grant was revoked.
     */
    public static function forUserIfLacksWorkspaceAccess(User $user): bool
    {
        if (self::canViewSomewhere($user)) {
            return false;
        }

        return self::forUser($user);
    }

    /**
     * Revoke every active MCP OAuth access token (and its refresh tokens) for the user.
     *
     * @return bool True when at least one grant was revoked.
     */
    public static function forUser(User $user): bool
    {
        return self::revoke(
            AccessToken::query()
                ->where('user_id', $user->id)
                ->mcpOAuth()
                ->where('revoked', false)
                ->get(),
        );
    }

    /**
     * Revoke active MCP OAuth grants for one OAuth client owned by the user.
     * When a workspace is provided, only grants bound to that workspace are revoked.
     *
     * @return bool True when at least one grant was revoked.
     */
    public static function forUserClient(User $user, string $clientId, ?Workspace $workspace = null): bool
    {
        $query = AccessToken::query()
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->mcpOAuth()
            ->where('revoked', false);

        if ($workspace !== null) {
            $query->where('workspace_id', $workspace->id);
        }

        return self::revoke($query->get());
    }

    /**
     * Revoke MCP OAuth grants bound to a specific workspace for a user
     * (e.g. when the member is removed from that workspace).
     *
     * @return bool True when at least one grant was revoked.
     */
    public static function forUserOnWorkspace(string $userId, Workspace $workspace): bool
    {
        return self::revoke(
            AccessToken::query()
                ->where('user_id', $userId)
                ->where('workspace_id', $workspace->id)
                ->mcpOAuth()
                ->where('revoked', false)
                ->get(),
        );
    }

    public static function canViewSomewhere(User $user): bool
    {
        return $user->workspaces()
            ->get()
            ->contains(fn (Workspace $workspace): bool => $user->can('view', $workspace));
    }

    /**
     * @param  Collection<int, AccessToken>  $tokens
     */
    private static function revoke(Collection $tokens): bool
    {
        if ($tokens->isEmpty()) {
            return false;
        }

        RevokeAccessTokens::execute($tokens);

        return true;
    }
}
