<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Account\AccountsRequiringCancel;
use App\Actions\Account\CancelAccounts;
use App\Actions\Account\DeleteAccount;
use App\Actions\Account\PurgeOwnedAccounts;
use App\Actions\Auth\LogoutAndInvalidateSession;
use App\Models\User;
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

        if (! CancelAccounts::execute(
            AccountsRequiringCancel::forDeletingUser($user, $account, $isOwner),
        )) {
            return false;
        }

        $settlement = DB::transaction(function () use ($user, $account, $isOwner): StrandedSettlement {
            $user->update(['current_workspace_id' => null]);

            if ($isOwner && $account) {
                $settlement = DeleteAccount::execute($account, $user);
            } else {
                // Members must never delete shared-account workspaces (even ones
                // they created). Only tear down accounts/workspaces they own.
                $user->workspaces()->detach();
                $settlement = new StrandedSettlement(
                    mediaPaths: PurgeOwnedAccounts::execute($user),
                );
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
}
