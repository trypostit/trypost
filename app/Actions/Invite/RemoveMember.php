<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Actions\User\EnsurePersonalAccount;
use App\Models\User;
use App\Models\Workspace;

class RemoveMember
{
    public static function execute(Workspace $workspace, string $userId): void
    {
        $user = User::query()->find($userId);

        $workspace->members()->detach($userId);

        if (! $user) {
            return;
        }

        $user->refresh();

        if ($user->current_workspace_id === $workspace->id) {
            $fallback = $user->workspaces()
                ->where('workspaces.id', '!=', $workspace->id)
                ->first();

            $user->update(['current_workspace_id' => $fallback?->id]);
            $user->refresh();
        }

        // Last membership on this shared account — move them to a personal
        // account so invite/create redirects cannot 403 as a non-owner.
        if (
            $user->account_id === $workspace->account_id
            && ! $user->isAccountOwner()
            && ! $user->workspaces()
                ->where('workspaces.account_id', $workspace->account_id)
                ->exists()
        ) {
            EnsurePersonalAccount::execute($user);
        }
    }
}
