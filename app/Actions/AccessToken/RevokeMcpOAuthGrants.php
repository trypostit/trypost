<?php

declare(strict_types=1);

namespace App\Actions\AccessToken;

use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RevokeMcpOAuthGrants
{
    /**
     * Revoke MCP OAuth grants only when the user can no longer create posts in
     * any workspace (demotion/removal that still leaves createPost elsewhere
     * must keep the grant — MCP is account-scoped via current workspace).
     *
     * @return bool True when at least one grant was revoked.
     */
    public static function forUserIfLacksCreatePost(User $user): bool
    {
        if (self::canCreatePostSomewhere($user)) {
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
     *
     * @return bool True when at least one grant was revoked.
     */
    public static function forUserClient(User $user, string $clientId): bool
    {
        return self::revoke(
            AccessToken::query()
                ->where('user_id', $user->id)
                ->where('client_id', $clientId)
                ->mcpOAuth()
                ->where('revoked', false)
                ->get(),
        );
    }

    public static function canCreatePostSomewhere(User $user): bool
    {
        return $user->workspaces()
            ->get()
            ->contains(fn (Workspace $workspace): bool => $user->can('createPost', $workspace));
    }

    /**
     * @param  Collection<int, AccessToken>  $tokens
     */
    private static function revoke(Collection $tokens): bool
    {
        if ($tokens->isEmpty()) {
            return false;
        }

        DB::transaction(function () use ($tokens): void {
            $tokenIds = $tokens->pluck('id');

            DB::table('oauth_refresh_tokens')
                ->whereIn('access_token_id', $tokenIds)
                ->update(['revoked' => true]);

            $tokens->each(function (AccessToken $token): void {
                $token->forceFill(['revoked' => true])->saveQuietly();
            });
        });

        return true;
    }
}
