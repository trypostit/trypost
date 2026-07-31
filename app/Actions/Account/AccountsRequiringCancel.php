<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Collection;

class AccountsRequiringCancel
{
    /**
     * Accounts whose Stripe subscription must be canceled before tearing down
     * $user (and, for owners, force-deleting remaining members' personal leftovers).
     *
     * @return Collection<int, Account>
     */
    public static function forDeletingUser(User $user, ?Account $account, bool $isOwner): Collection
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
