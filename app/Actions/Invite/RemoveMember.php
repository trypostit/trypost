<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Actions\Account\DeleteEmptyOwnedAccounts;
use App\Actions\Media\DeleteOrphanedMediaFiles;
use App\Actions\User\ReassignCurrentWorkspace;
use App\Actions\User\SettleStrandedMember;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class RemoveMember
{
    public static function execute(Workspace $workspace, string $userId): void
    {
        $mediaPaths = [];
        $emptyAccountIds = [];

        DB::transaction(function () use ($workspace, $userId, &$mediaPaths, &$emptyAccountIds): void {
            $account = $workspace->account;

            // Serialize with DeleteWorkspace / other RemoveMember calls on this
            // account so concurrent removals cannot skip stranded cleanup.
            if ($account?->id) {
                Account::query()->whereKey($account->id)->lockForUpdate()->first();
            }

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
            if (
                $account
                && $user->account_id === $account->id
                && $user->id !== $account->owner_id
            ) {
                $settled = SettleStrandedMember::execute($user, $account);
                $mediaPaths = $settled['media_paths'];
                $emptyAccountIds = $settled['empty_account_ids'];
            }
        });

        // Stripe cancel for empty personal leftovers — outside the account lock.
        DeleteEmptyOwnedAccounts::executeByIds($emptyAccountIds);
        DeleteOrphanedMediaFiles::execute($mediaPaths);
    }
}
