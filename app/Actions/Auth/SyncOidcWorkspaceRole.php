<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\UserWorkspace\Role as WorkspaceRole;
use App\Models\User;

/**
 * Derives a user's workspace role from the groups their identity provider
 * reports, on every sign-in.
 *
 * This is what lets an instance run without a standing local admin account:
 * add somebody to the admin group at the provider and they can administer the
 * workspace; take them out and they cannot, without anyone touching the
 * application. Offboarding then happens in exactly one place.
 */
class SyncOidcWorkspaceRole
{
    /**
     * @param  array<int, string>  $groups  Group names as reported by the provider.
     */
    public static function execute(User $user, array $groups): void
    {
        $adminGroups = self::configuredAdminGroups();

        // Without an admin group configured, roles stay under whoever manages
        // them in the application - invites, or an admin changing them by hand.
        if ($adminGroups === []) {
            return;
        }

        // Ownership is resolved through account.owner_id and outranks the
        // workspace role, so demoting an owner here would show "member" in the
        // interface while they keep every permission. Leave owners alone rather
        // than display a right they still have as one they lost.
        if ($user->account?->owner_id === $user->id) {
            return;
        }

        $target = array_intersect($groups, $adminGroups) !== []
            ? WorkspaceRole::Admin
            : WorkspaceRole::tryFrom((string) config('trypost.oidc_auto_join_role')) ?? WorkspaceRole::Member;

        foreach ($user->workspaces as $workspace) {
            if ($workspace->pivot->role === $target->value) {
                continue;
            }

            $workspace->members()->updateExistingPivot($user->id, ['role' => $target->value]);
        }
    }

    /**
     * @return array<int, string>
     */
    private static function configuredAdminGroups(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('trypost.oidc_admin_groups'))
        )));
    }
}
