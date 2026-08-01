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
     * $user.
     *
     * Owners: only the shared account. Invitees abandon their personal account
     * on accept, so members do not leave billed personals behind.
     *
     * Members: any account they still own (normally none after accept; empty
     * invite shells may remain if accept cleanup could not cancel Stripe).
     *
     * @return Collection<int, Account>
     */
    public static function forDeletingUser(User $user, ?Account $account, bool $isOwner): Collection
    {
        if ($isOwner && $account) {
            return collect([$account]);
        }

        return Account::query()
            ->where('owner_id', $user->id)
            ->get();
    }
}
