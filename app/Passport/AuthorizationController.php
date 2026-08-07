<?php

declare(strict_types=1);

namespace App\Passport;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
use Laravel\Passport\Scope;

/**
 * Always show the MCP consent screen so the user can pick a workspace.
 *
 * Passport otherwise skips consent when the user already granted the same
 * scopes (silent re-consent), which would bind the auth code via
 * current_workspace without an explicit pick.
 */
class AuthorizationController extends PassportAuthorizationController
{
    /**
     * @param  Scope[]  $scopes
     */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
