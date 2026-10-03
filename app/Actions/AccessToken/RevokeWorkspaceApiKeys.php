<?php

declare(strict_types=1);

namespace App\Actions\AccessToken;

use App\Models\AccessToken;
use App\Models\Workspace;

class RevokeWorkspaceApiKeys
{
    /**
     * Revoke personal-access API keys for a user on one workspace.
     * Used when the member is removed or loses admin access (manageTeam).
     *
     * @return int Number of tokens revoked.
     */
    public static function forUserOnWorkspace(string $userId, Workspace $workspace): int
    {
        return AccessToken::query()
            ->where('user_id', $userId)
            ->where('workspace_id', $workspace->id)
            ->where('revoked', false)
            ->personalAccessApiKey()
            ->update(['revoked' => true]);
    }

    /**
     * Admins (and account owners acting as admin) may keep workspace API keys.
     * Anyone else loses them.
     *
     * @return int Number of tokens revoked.
     */
    public static function forUserUnlessAdmin(string $userId, Workspace $workspace, bool $isAdmin): int
    {
        if ($isAdmin) {
            return 0;
        }

        return self::forUserOnWorkspace($userId, $workspace);
    }
}
