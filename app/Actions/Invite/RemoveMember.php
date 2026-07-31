<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Actions\User\DeleteOrRestoreStrandedMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class RemoveMember
{
    public static function execute(Workspace $workspace, string $userId): void
    {
        DB::transaction(function () use ($workspace, $userId): void {
            $user = User::query()->find($userId);

            $workspace->members()->detach($userId);

            if (! $user) {
                return;
            }

            $user->refresh();

            if ($user->current_workspace_id === $workspace->id) {
                // Same-account only — never leave current pointing across accounts
                // (WorkspacePolicy requires account_id match).
                $fallback = $user->workspaces()
                    ->where('workspaces.id', '!=', $workspace->id)
                    ->where('workspaces.account_id', $workspace->account_id)
                    ->first();

                $user->update(['current_workspace_id' => $fallback?->id]);
                $user->refresh();
            }

            // Last membership on this shared account — delete the invitee, or
            // restore a personal account that still has workspaces.
            $account = $workspace->account;

            if (
                $account
                && $user->account_id === $account->id
                && $user->id !== $account->owner_id
            ) {
                DeleteOrRestoreStrandedMember::execute($user, $account);
            }
        });
    }
}
