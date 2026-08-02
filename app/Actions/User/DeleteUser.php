<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Account\CancelAccountSubscription;
use App\Actions\Auth\LogoutAndInvalidateSession;
use App\Actions\Workspace\PurgeWorkspace;
use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeleteUser
{
    /**
     * Permanently delete the authenticated user and, when they own the
     * account, the shared account (after Stripe cancel).
     *
     * @return bool false when Stripe cancel failed — nothing local was deleted
     */
    public static function execute(User $user, Request $request): bool
    {
        $account = $user->account;
        $isOwner = $user->isAccountOwner();

        // Stripe cancel must succeed before any local teardown. Members own no
        // account (closed-account model), so only the owner's account matters.
        if ($isOwner && $account && ! CancelAccountSubscription::execute($account)) {
            return false;
        }

        $settlement = DB::transaction(function () use ($user, $account, $isOwner): StrandedSettlement {
            $user->update(['current_workspace_id' => null]);

            if ($isOwner && $account) {
                $settlement = self::deleteOwnedAccount($user, $account);
            } else {
                // Members must never delete shared-account workspaces (even ones
                // they created). They own nothing, so just detach from the shared ones.
                $user->workspaces()->detach();
                $settlement = StrandedSettlement::none();
                $user->update(['account_id' => null]);
            }

            return $settlement->merge(new StrandedSettlement(
                mediaPaths: PurgeUserAccess::execute($user),
            ));
        });

        $settlement->flush();

        // Logout while the user row still exists — SessionGuard cycles the
        // remember token via save(), which fails if the user was already deleted.
        LogoutAndInvalidateSession::execute($request);
        $user->delete();

        return true;
    }

    /**
     * Tear down the owned shared account: workspaces, invites, and delete its
     * members. Must run inside a DB transaction (locks the account row).
     */
    private static function deleteOwnedAccount(User $owner, Account $account): StrandedSettlement
    {
        $locked = Account::query()->whereKey($account->id)->lockForUpdate()->first();

        if (! $locked) {
            return StrandedSettlement::none();
        }

        $settlement = StrandedSettlement::none();

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

        // Every non-owner member of this account is deleted with it.
        $settlement = $settlement->merge(
            SettleStrandedMember::forAccountMembers($account, exceptUserId: $owner->id),
        );

        $owner->update(['account_id' => null]);

        if (Account::query()->whereKey($account->id)->exists()) {
            $account->subscriptions()->delete();
            $account->delete();
        }

        return $settlement;
    }
}
