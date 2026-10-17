<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Hands an account over to group management by clearing its owner.
 *
 * Ownership outranks the workspace role, so as long as one person holds it
 * their rights cannot follow their provider groups - they are permanently
 * outside the very system that is supposed to govern access. On an instance
 * where the provider decides who may do what, that is the one exception too
 * many.
 *
 * `accounts.owner_id` is nullable and every check against it is a comparison,
 * so an account without an owner is a supported state: the owner-only actions
 * (deleting a workspace, billing) become unavailable to everyone, and
 * everything operational - connecting accounts, managing the team, inviting -
 * runs on the admin role, which does follow the groups.
 */
class ReleaseAccountOwnership
{
    public static function execute(User $user): bool
    {
        if (! config('trypost.oidc_release_ownership')) {
            return false;
        }

        $account = $user->account;

        if (! $account || blank($account->owner_id)) {
            return false;
        }

        $account->update(['owner_id' => null]);

        return true;
    }
}
