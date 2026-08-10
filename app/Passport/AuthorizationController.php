<?php

declare(strict_types=1);

namespace App\Passport;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Exceptions\OAuthServerException;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
use Laravel\Passport\Scope;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueOAuthServerException;
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
 * Browser non-redirect OAuth failures get an Inertia error page; JSON clients
 * and redirect errors (prompt=none) keep Passport's response.
 */
class AuthorizationController extends PassportAuthorizationController
{
    public function authorize(
        ServerRequestInterface $psrRequest,
        Request $request,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $viewResponse
    ): Response|AuthorizationViewResponse {
        if ($this->guard->guest()) {
            $prompt = $request->string('prompt')->explode(' ')->map(trim(...))->filter()->values();

            // prompt=none must not show a login UI — fall through to Passport.
            if ($prompt->doesntContain('none')) {
                $this->promptForLogin($request);
            }
        }

        try {
            return parent::authorize($psrRequest, $request, $psrResponse, $viewResponse);
        } catch (OAuthServerException $exception) {
            if ($request->expectsJson() || $exception->getResponse()->isRedirection()) {
                throw $exception;
            }

            $oauth = $exception->getPrevious();

            return Inertia::render('mcp/AuthorizeError', $oauth instanceof LeagueOAuthServerException
                ? [
                    'error' => $oauth->getErrorType(),
                    'errorDescription' => $oauth->getMessage(),
                ]
                : [
                    'error' => 'server_error',
                    'errorDescription' => __('mcp.authorize.error_body'),
                ])->toResponse($request);
        }
    }

    /**
     * @param  Scope[]  $scopes
     */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
