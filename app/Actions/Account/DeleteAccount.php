<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Actions\User\DeleteOrRestoreStrandedMember;
use App\Actions\User\ReassignCurrentWorkspace;
use App\Actions\Workspace\PurgeWorkspace;
use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;

class DeleteAccount
{
    /**
     * Tear down a shared account owned by $owner: workspaces, invites, and
     * force-delete remaining members. Does not delete the owner user row.
     *
     * @return list<string> media paths for DeleteOrphanedMediaFiles after commit
     */
    public static function execute(Account $account, User $owner): array
    {
        $mediaPaths = [];

        $owner->update(['current_workspace_id' => null]);

        Workspace::query()
            ->where('account_id', $account->id)
            ->get()
            ->each(function (Workspace $workspace) use ($owner, &$mediaPaths): void {
                ReassignCurrentWorkspace::awayFromWorkspace(
                    $workspace,
                    exceptUserId: $owner->id,
                );

                $mediaPaths = [
                    ...$mediaPaths,
                    ...PurgeWorkspace::execute($workspace),
                ];
            });

        $owner->workspaces()->detach();

        Invite::query()->where('account_id', $account->id)->delete();

        $mediaPaths = [
            ...$mediaPaths,
            ...DeleteOrRestoreStrandedMember::forceDeleteMembers(
                $account,
                exceptUserId: $owner->id,
            ),
        ];

        $owner->update(['account_id' => null]);

        if (Account::query()->whereKey($account->id)->exists()) {
            $account->subscriptions()->delete();
            $account->delete();
        }

        return $mediaPaths;
    }
}
