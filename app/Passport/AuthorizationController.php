<?php

declare(strict_types=1);

namespace App\Passport;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
use Laravel\Passport\Scope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Always show the MCP consent screen so the user can pick a workspace.
 *
 * Passport otherwise skips consent when the user already granted the same
 * scopes (silent re-consent), which would bind the auth code via
 * current_workspace without an explicit pick.
 *
 * Guests are sent to login before client validation so a stale MCP Inspector
 * client_id does not return invalid_client JSON instead of the login page.
 */
class AuthorizationController extends PassportAuthorizationController
{
    /**
     * Authorize a client to access the user's account.
     */
    public function authorize(
        ServerRequestInterface $psrRequest,
        Request $request,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $viewResponse
    ): Response|AuthorizationViewResponse {
        if ($this->guard->guest()) {
            $prompt = $request->string('prompt')->explode(' ')->map(trim(...))->filter()->values();

            // prompt=none must not show a login UI — fall through to Passport
            // validation so the client receives login_required / invalid_client.
            if ($prompt->doesntContain('none')) {
                $this->promptForLogin($request);
            }
        }

        return parent::authorize($psrRequest, $request, $psrResponse, $viewResponse);
    }

    /**
     * @param  Scope[]  $scopes
     */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
