<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Account\DeleteEmptyOwnedAccounts;
use App\Actions\Account\PurgeOwnedAccounts;
use App\Models\Account;
use App\Models\User;

class DeleteOrRestoreStrandedMember
{
    /**
     * After losing memberships on a shared account: restore a personal account
     * that still has workspaces, otherwise delete the user (and empty orphans).
     *
     * @return list<string> media paths for DeleteOrphanedMediaFiles after commit
     */
    public static function execute(User $user, Account $leavingAccount): array
    {
        return self::process($user, $leavingAccount, forceDelete: false);
    }

    /**
     * Owner account teardown: always delete the member, including leftover
     * personal accounts/workspaces.
     *
     * @return list<string>
     */
    public static function forceDelete(User $user, Account $leavingAccount): array
    {
        return self::process($user, $leavingAccount, forceDelete: true);
    }

    /**
     * Members on $account with no remaining memberships on that account.
     * Used after deleting a workspace.
     *
     * @return list<string>
     */
    public static function strandedWithoutMemberships(
        Account $account,
        ?string $exceptUserId = null,
    ): array {
        return self::forAccountMembers(
            $account,
            $exceptUserId,
            onlyWithoutAccountWorkspaces: true,
            forceDelete: false,
        );
    }

    /**
     * Every non-owner member still pointing at $account. Used during owner
     * account teardown (never restore to a personal account).
     *
     * @return list<string>
     */
    public static function forceDeleteMembers(
        Account $account,
        ?string $exceptUserId = null,
    ): array {
        return self::forAccountMembers(
            $account,
            $exceptUserId,
            onlyWithoutAccountWorkspaces: false,
            forceDelete: true,
        );
    }

    /**
     * @return list<string>
     */
    private static function process(User $user, Account $leavingAccount, bool $forceDelete): array
    {
        if ($user->id === $leavingAccount->owner_id) {
            return [];
        }

        if ($user->workspaces()
            ->where('workspaces.account_id', $leavingAccount->id)
            ->exists()
        ) {
            return [];
        }

        if (! $forceDelete) {
            $personalAccount = Account::query()
                ->where('owner_id', $user->id)
                ->where('id', '!=', $leavingAccount->id)
                ->whereHas('workspaces')
                ->first();

            if ($personalAccount) {
                $fallback = $user->workspaces()
                    ->where('workspaces.account_id', $personalAccount->id)
                    ->first();

                $user->update([
                    'account_id' => $personalAccount->id,
                    'current_workspace_id' => $fallback?->id,
                ]);

                return [];
            }
        }

        $mediaPaths = $forceDelete
            ? PurgeOwnedAccounts::execute($user, $leavingAccount)
            : [];

        if (! $forceDelete) {
            DeleteEmptyOwnedAccounts::execute(
                $user,
                exceptAccountId: $leavingAccount->id,
            );
        }

        $mediaPaths = [
            ...$mediaPaths,
            ...PurgeUserAccess::execute($user),
        ];

        $user->workspaces()->detach();
        $user->update([
            'account_id' => null,
            'current_workspace_id' => null,
        ]);
        $user->delete();

        return $mediaPaths;
    }

    /**
     * @return list<string>
     */
    private static function forAccountMembers(
        Account $account,
        ?string $exceptUserId,
        bool $onlyWithoutAccountWorkspaces,
        bool $forceDelete,
    ): array {
        $mediaPaths = [];

        User::query()
            ->where('account_id', $account->id)
            ->when(
                $exceptUserId,
                fn ($query) => $query->where('id', '!=', $exceptUserId),
            )
            ->when(
                $onlyWithoutAccountWorkspaces,
                fn ($query) => $query->whereDoesntHave(
                    'workspaces',
                    fn ($workspaces) => $workspaces->where('workspaces.account_id', $account->id),
                ),
            )
            ->get()
            ->each(function (User $member) use ($account, $forceDelete, &$mediaPaths): void {
                $mediaPaths = [
                    ...$mediaPaths,
                    ...($forceDelete
                        ? self::forceDelete($member, $account)
                        : self::execute($member, $account)),
                ];
            });

        return $mediaPaths;
    }
}
