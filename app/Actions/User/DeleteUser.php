<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Account\CancelAccountSubscription;
use App\Actions\Account\DeleteAccount;
use App\Actions\Account\PurgeOwnedAccounts;
use App\Actions\Auth\LogoutAndInvalidateSession;
use App\Actions\Media\DeleteOrphanedMediaFiles;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        foreach (self::accountsRequiringCancel($user, $account, $isOwner) as $billable) {
            if (! CancelAccountSubscription::execute($billable)) {
                return false;
            }
        }

        $mediaPaths = DB::transaction(function () use ($user, $account, $isOwner): array {
            $user->update(['current_workspace_id' => null]);

            $mediaPaths = [];

            if ($isOwner && $account) {
                $mediaPaths = DeleteAccount::execute($account, $user);
            } else {
                // Members must never delete shared-account workspaces (even ones
                // they created). Only tear down accounts/workspaces they own.
                $user->workspaces()->detach();
                $mediaPaths = PurgeOwnedAccounts::execute($user);
                $user->update(['account_id' => null]);
            }

            return [
                ...$mediaPaths,
                ...PurgeUserAccess::execute($user),
            ];
        });

        DeleteOrphanedMediaFiles::execute($mediaPaths);

        // Logout while the user row still exists — SessionGuard cycles the
        // remember token via save(), which fails if the user was already deleted.
        LogoutAndInvalidateSession::execute($request);
        $user->delete();

        return true;
    }

    /**
     * Accounts whose Stripe subscription must be canceled before local teardown.
     *
     * @return Collection<int, Account>
     */
    public static function accountsRequiringCancel(User $user, ?Account $account, bool $isOwner): Collection
    {
        if ($isOwner && $account) {
            $memberIds = User::query()
                ->where('account_id', $account->id)
                ->where('id', '!=', $user->id)
                ->pluck('id');

            $memberOwnedAccounts = Account::query()
                ->whereIn('owner_id', $memberIds)
                ->where('id', '!=', $account->id)
                ->get();

            return collect([$account])->merge($memberOwnedAccounts)->unique('id')->values();
        }

        return Account::query()
            ->where('owner_id', $user->id)
            ->get();
    }
}
