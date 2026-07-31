<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\Account;
use App\Models\User;

class DeleteEmptyOwnedAccounts
{
    /**
     * Cancel any Stripe subscription, then delete accounts the user owns that
     * have no workspaces. Prefer calling outside a held DB lock.
     *
     * Skips an account when Stripe cancel fails so we never drop the local row
     * while billing continues remotely.
     *
     * @return list<string> IDs of accounts that were deleted
     */
    public static function execute(
        User $user,
        ?string $onlyAccountId = null,
        ?string $exceptAccountId = null,
    ): array {
        $ids = Account::query()
            ->where('owner_id', $user->id)
            ->when(
                $onlyAccountId,
                fn ($query) => $query->whereKey($onlyAccountId),
            )
            ->when(
                $exceptAccountId,
                fn ($query) => $query->where('id', '!=', $exceptAccountId),
            )
            ->whereDoesntHave('workspaces')
            ->pluck('id')
            ->all();

        return self::executeByIds($ids, $user);
    }

    /**
     * Cancel Stripe then delete the given empty accounts (by id).
     * Safe to call after a lock is released — including after the owner user
     * row was deleted (owner_id may already be null via FK).
     *
     * @param  list<string>  $accountIds
     * @return list<string> IDs of accounts that were deleted
     */
    public static function executeByIds(array $accountIds, ?User $user = null): array
    {
        $deleted = [];

        foreach ($accountIds as $accountId) {
            $orphan = Account::query()->find($accountId);

            if (! $orphan || $orphan->workspaces()->exists()) {
                continue;
            }

            if (! CancelAccountSubscription::execute($orphan)) {
                continue;
            }

            if ($user && $user->account_id === $orphan->id) {
                $user->update(['account_id' => null]);
            }

            $orphan->delete();
            $deleted[] = $orphan->id;
        }

        return $deleted;
    }
}
