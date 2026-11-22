<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * Workspace access granted to users who sign in through OIDC, as the pivot
 * columns of user_workspace.
 *
 * OIDC_AUTO_JOIN_ROLE predates the move from workspace roles to the is_admin
 * and requires_approval flags, so it keeps the role names it was documented
 * with and maps them the same way the migration mapped existing rows:
 * admin, member, viewer (posts need approval).
 */
final class OidcWorkspaceAccess
{
    /**
     * @return array{is_admin: bool, requires_approval: bool}
     */
    public static function admin(): array
    {
        return ['is_admin' => true, 'requires_approval' => false];
    }

    /**
     * Access for everyone outside the admin groups.
     *
     * @return array{is_admin: bool, requires_approval: bool}
     */
    public static function configuredDefault(): array
    {
        return match (strtolower(trim((string) config('trypost.oidc_auto_join_role')))) {
            'admin' => self::admin(),
            'viewer' => ['is_admin' => false, 'requires_approval' => true],
            default => ['is_admin' => false, 'requires_approval' => false],
        };
    }
}
