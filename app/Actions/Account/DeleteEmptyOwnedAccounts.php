<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\Account;
use App\Models\User;

class DeleteEmptyOwnedAccounts
{
    /**
     * Cancel any Stripe subscription, then delete accounts the user owns that
     * have no workspaces. Prefer calling outside a held DB lock when possible.
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
        $deleted = [];

        Account::query()
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
            ->get()
            ->each(function (Account $orphan) use ($user, &$deleted): void {
                if (! CancelAccountSubscription::execute($orphan)) {
                    return;
                }

                if ($user->account_id === $orphan->id) {
                    $user->update(['account_id' => null]);
                }

                $orphan->delete();
                $deleted[] = $orphan->id;
            });

        return $deleted;
    }
}
