<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Actions\User\ReassignCurrentWorkspace;
use App\Actions\User\SettleStrandedMember;
use App\Actions\User\StrandedSettlement;
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
     * Must run inside a DB transaction. Locks the account row so concurrent
     * DeleteWorkspace / RemoveMember cannot settle a member off this account
     * before forceDeleteMembers runs.
     *
     * Callers must {@see StrandedSettlement::flush()} after commit.
     */
    public static function execute(Account $account, User $owner): StrandedSettlement
    {
        $locked = Account::query()->whereKey($account->id)->lockForUpdate()->first();

        if (! $locked) {
            return StrandedSettlement::none();
        }

        $settlement = StrandedSettlement::none();

        $owner->update(['current_workspace_id' => null]);

        Workspace::query()
            ->where('account_id', $account->id)
            ->get()
            ->each(function (Workspace $workspace) use ($owner, &$settlement): void {
                ReassignCurrentWorkspace::awayFromWorkspace(
                    $workspace,
                    exceptUserId: $owner->id,
                );

                $settlement = $settlement->merge(new StrandedSettlement(
                    mediaPaths: PurgeWorkspace::execute($workspace),
                ));
            });

        $owner->workspaces()->detach();

        Invite::query()->where('account_id', $account->id)->delete();

        $settlement = $settlement->merge(
            SettleStrandedMember::forceDeleteMembers(
                $account,
                exceptUserId: $owner->id,
            ),
        );

        $owner->update(['account_id' => null]);

        if (Account::query()->whereKey($account->id)->exists()) {
            $account->subscriptions()->delete();
            $account->delete();
        }

        return $settlement;
    }
}
