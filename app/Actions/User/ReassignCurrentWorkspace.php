<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\User;
use App\Models\Workspace;

class ReassignCurrentWorkspace
{
    /**
     * If $user's current workspace is $workspace, point them at another
     * membership on the same account (or null). Never cross-account.
     */
    public static function forUserAwayFrom(User $user, Workspace $workspace): void
    {
        if ($user->current_workspace_id !== $workspace->id) {
            return;
        }

        $fallback = $user->workspaces()
            ->where('workspaces.id', '!=', $workspace->id)
            ->where('workspaces.account_id', $workspace->account_id)
            ->first();

        $user->update(['current_workspace_id' => $fallback?->id]);
    }

    /**
     * Point every user whose current workspace is $workspace at another
     * same-account membership (or null). Used when the workspace is deleted.
     */
    public static function awayFromWorkspace(Workspace $workspace, ?string $exceptUserId = null): void
    {
        User::query()
            ->where('current_workspace_id', $workspace->id)
            ->when(
                $exceptUserId,
                fn ($query) => $query->where('id', '!=', $exceptUserId),
            )
            ->get()
            ->each(fn (User $user) => self::forUserAwayFrom($user, $workspace));
    }
}
