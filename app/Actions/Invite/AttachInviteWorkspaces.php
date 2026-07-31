<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class AttachInviteWorkspaces
{
    /**
     * @param  Collection<int, Workspace>  $workspaces
     */
    public static function execute(User $user, Invite $invite, Collection $workspaces): void
    {
        foreach ($workspaces as $workspace) {
            $alreadyMember = $workspace->members()
                ->where('users.id', $user->id)
                ->exists();

            // Never overwrite an existing pivot role (avoids demoting admins).
            if (! $alreadyMember) {
                $workspace->members()->attach($user->id, [
                    'role' => $invite->role->value,
                ]);
            }

            // Accept often switches account_id while an old personal workspace is
            // still current — always land on a workspace of the invite account.
            if (! self::currentWorkspaceBelongsToAccount($user)) {
                $user->update(['current_workspace_id' => $workspace->id]);
                $user->refresh();
            }
        }
    }

    public static function currentWorkspaceBelongsToAccount(User $user): bool
    {
        if (! $user->current_workspace_id || ! $user->account_id) {
            return false;
        }

        return $user->workspaces()
            ->where('workspaces.id', $user->current_workspace_id)
            ->where('workspaces.account_id', $user->account_id)
            ->exists();
    }
}
