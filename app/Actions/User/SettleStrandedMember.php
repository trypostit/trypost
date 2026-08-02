<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\Account;
use App\Models\User;

class SettleStrandedMember
{
    /**
     * After losing all memberships on a shared account, the invitee is deleted.
     * Members never own an account (closed-account model), so there is nothing
     * else to tear down.
     *
     * Callers must {@see StrandedSettlement::flush()} after releasing any lock.
     */
    public static function execute(User $user, Account $leavingAccount): StrandedSettlement
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

        $mediaPaths = PurgeUserAccess::execute($user);

        $user->workspaces()->detach();
        $user->update([
            'account_id' => null,
            'current_workspace_id' => null,
        ]);
        $user->delete();

        return new StrandedSettlement(mediaPaths: $mediaPaths);
    }

    /**
     * Delete every non-owner member still pointing at $account. With
     * $onlyWithoutMemberships, only those with no remaining workspaces on it.
     */
    public static function forAccountMembers(
        Account $account,
        ?string $exceptUserId = null,
        bool $onlyWithoutMemberships = false,
    ): StrandedSettlement {
        $settlement = StrandedSettlement::none();

        User::query()
            ->where('account_id', $account->id)
            ->when(
                $exceptUserId,
                fn ($query) => $query->where('id', '!=', $exceptUserId),
            )
            ->when(
                $onlyWithoutMemberships,
                fn ($query) => $query->whereDoesntHave(
                    'workspaces',
                    fn ($workspaces) => $workspaces->where('workspaces.account_id', $account->id),
                ),
            )
            ->get()
            ->each(function (User $member) use ($account, &$settlement): void {
                $settlement = $settlement->merge(self::execute($member, $account));
            });

        return $settlement;
    }
}
