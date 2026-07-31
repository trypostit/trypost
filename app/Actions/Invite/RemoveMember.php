<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Actions\Media\DeleteOrphanedMediaFiles;
use App\Actions\User\DeleteOrRestoreStrandedMember;
use App\Actions\User\ReassignCurrentWorkspace;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class RemoveMember
{
    public static function execute(Workspace $workspace, string $userId): void
    {
        $mediaPaths = [];

        DB::transaction(function () use ($workspace, $userId, &$mediaPaths): void {
            $user = User::query()->find($userId);

            $workspace->members()->detach($userId);

            if (! $user) {
                return;
            }

            $user->refresh();

            if ($user->current_workspace_id === $workspace->id) {
                ReassignCurrentWorkspace::forUserAwayFrom($user, $workspace);
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
                $mediaPaths = DeleteOrRestoreStrandedMember::execute($user, $account);
            }
        });

        DeleteOrphanedMediaFiles::execute($mediaPaths);
    }
}
