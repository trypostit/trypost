<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\Account;
use App\Models\User;

class EnsurePersonalAccount
{
    /**
     * Ensure the user owns a personal account and points at it.
     *
     * Used when a member loses access to a shared account (workspace deleted
     * or the account owner closes the account) so they can keep logging in
     * and create their own workspace instead of ending up with account_id null.
     */
    public static function execute(User $user): Account
    {
        if ($user->account_id && $user->isAccountOwner()) {
            return $user->account;
        }

        $personalAccount = Account::query()
            ->where('owner_id', $user->id)
            ->when(
                $user->account_id,
                fn ($query) => $query->where('id', '!=', $user->account_id),
            )
            ->first();

        if (! $personalAccount) {
            $personalAccount = Account::create([
                'name' => "{$user->name}'s Account",
                'billing_email' => $user->email,
                'owner_id' => $user->id,
            ]);
        }

        $user->update(['account_id' => $personalAccount->id]);

        return $personalAccount;
    }
}
