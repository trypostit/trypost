<?php

declare(strict_types=1);

namespace App\Actions\AccessToken;

use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;

class HasConnectedMcpClient
{
    /**
     * Whether the user has an AI connected to this workspace through MCP, as
     * the MCP settings list it: each connection belongs to one workspace.
     */
    public static function execute(User $user, Workspace $workspace): bool
    {
        return AccessToken::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->connectedMcpOAuth()
            ->exists();
    }
}
