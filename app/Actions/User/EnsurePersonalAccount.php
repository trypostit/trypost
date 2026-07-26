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

        $currentBelongsToPersonal = $user->current_workspace_id
            && $user->workspaces()
                ->where('workspaces.account_id', $personalAccount->id)
                ->where('workspaces.id', $user->current_workspace_id)
                ->exists();

        if (! $currentBelongsToPersonal) {
            $fallback = $user->workspaces()
                ->where('workspaces.account_id', $personalAccount->id)
                ->first();

            $user->update(['current_workspace_id' => $fallback?->id]);
        }

        return $personalAccount;
    }

    /**
     * Move members off a shared account onto a personal account they own.
     *
     * @param  bool  $onlyWithoutAccountWorkspaces  When true, only members with no
     *                                              remaining memberships on this
     *                                              account's workspaces are rehomed
     *                                              (workspace delete). When false,
     *                                              every other member is rehomed
     *                                              (account delete).
     */
    public static function rehomeAccountMembers(
        Account $account,
        ?string $exceptUserId = null,
        bool $onlyWithoutAccountWorkspaces = false,
    ): void {
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
            ->each(fn (User $member) => self::execute($member));
    }
}
