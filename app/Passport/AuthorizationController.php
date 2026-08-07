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
 *
 * Browser / Inertia requests that fail OAuth validation get an Inertia error
 * page instead of raw JSON (which breaks the post-login Inertia redirect).
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

        try {
            return parent::authorize($psrRequest, $request, $psrResponse, $viewResponse);
        } catch (OAuthServerException $exception) {
            if (! $this->shouldRenderAuthorizationErrorPage($request)) {
                throw $exception;
            }

            return $this->authorizationErrorPage($request, $exception);
        }
    }

    /**
     * @param  Scope[]  $scopes
     */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }

    /**
     * HTML / Inertia navigations need a page — JSON stays for API-style clients.
     */
    private function shouldRenderAuthorizationErrorPage(Request $request): bool
    {
        // Login (and other Inertia forms) follow the intended /oauth/authorize
        // redirect with X-Inertia; raw OAuth JSON triggers the "plain JSON
        // response was received" client error.
        if ($request->inertia()) {
            return true;
        }

        return $request->acceptsHtml() && ! $request->expectsJson();
    }

    private function authorizationErrorPage(Request $request, OAuthServerException $exception): Response
    {
        $payload = $this->oauthErrorPayload($exception);

        return Inertia::render('mcp/AuthorizeError', [
            'error' => (string) ($payload['error'] ?? 'server_error'),
            'errorDescription' => (string) ($payload['error_description'] ?? __('mcp.authorize.error_body')),
        ])->toResponse($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function oauthErrorPayload(OAuthServerException $exception): array
    {
        $previous = $exception->getPrevious();

        if ($previous instanceof LeagueOAuthServerException) {
            return $previous->getPayload();
        }

        $decoded = json_decode($exception->getResponse()->getContent() ?: '[]', true);

        return is_array($decoded) ? $decoded : [];
    }
}
