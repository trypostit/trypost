<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Account\PurgeOwnedAccounts;
use App\Models\Account;
use App\Models\User;

class SettleStrandedMember
{
    /**
     * After losing memberships on a shared account: restore a personal account
     * that still has workspaces, otherwise delete the user.
     *
     * Callers must {@see StrandedSettlement::flush()} after releasing any
     * account lock so Stripe cancel is not held under it.
     */
    public static function execute(User $user, Account $leavingAccount): StrandedSettlement
    {
        return self::process($user, $leavingAccount, forceDelete: false);
    }

    /**
     * Owner account teardown: always delete the member, including leftover
     * personal accounts/workspaces (Stripe for those was canceled upstream).
     */
    public static function forceDelete(User $user, Account $leavingAccount): StrandedSettlement
    {
        return self::process($user, $leavingAccount, forceDelete: true);
    }

    /**
     * Members on $account with no remaining memberships on that account.
     */
    public static function strandedWithoutMemberships(
        Account $account,
        ?string $exceptUserId = null,
    ): StrandedSettlement {
        return self::forAccountMembers(
            $account,
            $exceptUserId,
            onlyWithoutAccountWorkspaces: true,
            forceDelete: false,
        );
    }

    /**
     * Every non-owner member still pointing at $account (owner account teardown).
     */
    public static function forceDeleteMembers(
        Account $account,
        ?string $exceptUserId = null,
    ): StrandedSettlement {
        return self::forAccountMembers(
            $account,
            $exceptUserId,
            onlyWithoutAccountWorkspaces: false,
            forceDelete: true,
        );
    }

    private static function process(User $user, Account $leavingAccount, bool $forceDelete): StrandedSettlement
    {
        if ($user->id === $leavingAccount->owner_id) {
            return StrandedSettlement::none();
        }

        if ($user->workspaces()
            ->where('workspaces.account_id', $leavingAccount->id)
            ->exists()
        ) {
            return StrandedSettlement::none();
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

                return StrandedSettlement::none();
            }
        }

        $mediaPaths = $forceDelete
            ? PurgeOwnedAccounts::execute($user, $leavingAccount)
            : [];

        $emptyAccountIds = [];

        if (! $forceDelete) {
            $emptyAccountIds = Account::query()
                ->where('owner_id', $user->id)
                ->where('id', '!=', $leavingAccount->id)
                ->whereDoesntHave('workspaces')
                ->pluck('id')
                ->all();
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

        return new StrandedSettlement(
            mediaPaths: $mediaPaths,
            emptyAccountIds: $emptyAccountIds,
        );
    }

    private static function forAccountMembers(
        Account $account,
        ?string $exceptUserId,
        bool $onlyWithoutAccountWorkspaces,
        bool $forceDelete,
    ): StrandedSettlement {
        $settlement = StrandedSettlement::none();

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
            ->each(function (User $member) use ($account, $forceDelete, &$settlement): void {
                $settled = $forceDelete
                    ? self::forceDelete($member, $account)
                    : self::execute($member, $account);

                $settlement = $settlement->merge($settled);
            });

        return $settlement;
    }
}
