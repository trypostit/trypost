<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Account\PurgeOwnedAccounts;
use App\Models\Account;
use App\Models\User;

class SettleStrandedMember
{
    /**
     * After losing memberships on a shared account: delete the invitee and
     * queue empty personal account shells for Stripe cancel + delete.
     *
     * Callers must {@see StrandedSettlement::flush()} after releasing any
     * account lock so Stripe cancel is not held under it.
     */
    public static function execute(User $user, Account $leavingAccount): StrandedSettlement
    {
        return self::process($user, $leavingAccount);
    }

    /**
     * Owner account teardown: same as {@see execute()} — invitees never keep a
     * personal account with workspaces after joining a shared account.
     */
    public static function forceDelete(User $user, Account $leavingAccount): StrandedSettlement
    {
        return self::process($user, $leavingAccount);
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
        );
    }

    private static function process(User $user, Account $leavingAccount): StrandedSettlement
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

        // Defensive: any owned account that still has workspaces is torn down
        // locally. AcceptInvite abandons the previous personal account, so this
        // should be empty in normal product flows.
        $mediaPaths = PurgeOwnedAccounts::execute(
            $user,
            $leavingAccount,
            onlyWithWorkspaces: true,
        );

        $emptyAccountIds = Account::query()
            ->where('owner_id', $user->id)
            ->where('id', '!=', $leavingAccount->id)
            ->whereDoesntHave('workspaces')
            ->pluck('id')
            ->all();

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
            ->each(function (User $member) use ($account, &$settlement): void {
                $settlement = $settlement->merge(
                    self::execute($member, $account),
                );
            });

        return $settlement;
    }
}
