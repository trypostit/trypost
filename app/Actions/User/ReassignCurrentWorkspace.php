<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;

class ReassignCurrentWorkspace
{
    /**
     * If $user's current workspace is $workspace, point them at another
     * membership on the same account (or null). Never cross-account.
     *
     * When $attachOwnerFallback is true and the user owns the deleting
     * account, they may be attached as admin to another account workspace
     * they are not yet a member of (workspace-delete owner safety net).
     */
    public static function forUserAwayFrom(
        User $user,
        Workspace $workspace,
        bool $attachOwnerFallback = false,
        ?Account $account = null,
    ): void {
        if ($user->current_workspace_id !== $workspace->id) {
            return;
        }

        $fallback = $user->workspaces()
            ->where('workspaces.id', '!=', $workspace->id)
            ->where('workspaces.account_id', $workspace->account_id)
            ->first();

        if (! $fallback && $attachOwnerFallback) {
            $fallback = self::ownerAccountFallback($user, $workspace, $account);
        }

        $user->update(['current_workspace_id' => $fallback?->id]);
    }

    /**
     * Point every user whose current workspace is $workspace at another
     * same-account membership (or null). Used when the workspace is deleted.
     */
    public static function awayFromWorkspace(
        Workspace $workspace,
        ?string $exceptUserId = null,
        bool $attachOwnerFallback = false,
        ?Account $account = null,
    ): void {
        User::query()
            ->where('current_workspace_id', $workspace->id)
            ->when(
                $exceptUserId,
                fn ($query) => $query->where('id', '!=', $exceptUserId),
            )
            ->get()
            ->each(fn (User $user) => self::forUserAwayFrom(
                $user,
                $workspace,
                $attachOwnerFallback,
                $account,
            ));
    }

    private static function ownerAccountFallback(
        User $user,
        Workspace $deleting,
        ?Account $account,
    ): ?Workspace {
        // Use the already-loaded account owner_id — never isAccountOwner(),
        // which can touch the account relation under shouldBeStrict().
        $isOwnerOfDeletingAccount = $account !== null
            && $user->id === $account->owner_id
            && $user->account_id === $deleting->account_id;

        if (! $isOwnerOfDeletingAccount) {
            return null;
        }

        $fallback = Workspace::query()
            ->where('account_id', $deleting->account_id)
            ->where('id', '!=', $deleting->id)
            ->first();

        if ($fallback && ! $user->belongsToWorkspace($fallback)) {
            $fallback->members()->syncWithoutDetaching([
                $user->id => ['role' => Role::Admin->value],
            ]);
        }

        return $fallback;
    }
}
