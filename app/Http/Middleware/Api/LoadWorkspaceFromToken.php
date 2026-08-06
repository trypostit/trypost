<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Models\AccessToken;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken as PassportAccessToken;
use Symfony\Component\HttpFoundation\Response;

class LoadWorkspaceFromToken
{
    public function handle(Request $request, Closure $next, ?string $context = null): Response
    {
        $user = $request->user();
        $authenticatedToken = $user?->token();

        if (! $authenticatedToken instanceof PassportAccessToken) {
            return response()->json(['message' => 'Token not found.'], Response::HTTP_UNAUTHORIZED);
        }

        $token = AccessToken::query()->find($authenticatedToken->oauth_access_token_id);

        if ($token === null) {
            return response()->json(['message' => 'Token not found.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($token->expires_at?->isPast()) {
            return response()->json(['message' => 'Token expired.'], Response::HTTP_UNAUTHORIZED);
        }

        // Personal API tokens (created from settings) bind to a specific
        // workspace at creation. OAuth tokens (e.g. ChatGPT MCP) don't —
        // they follow the user's current workspace.
        $workspace = $token->workspace_id
            ? Workspace::find($token->workspace_id)
            : $user->currentWorkspace;

        if (! $workspace) {
            return response()->json(['message' => 'No workspace selected.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->can('view', $workspace)) {
            return response()->json(['message' => 'Workspace access denied.'], Response::HTTP_FORBIDDEN);
        }

        if ($context === 'mcp') {
            if (! $token->isActiveMcpGrant() || ! $authenticatedToken->can('mcp:use')) {
                return response()->json(['message' => 'MCP OAuth authorization required.'], Response::HTTP_FORBIDDEN);
            }
        } else {
            if (! $token->isPersonalAccessToken()) {
                return response()->json(['message' => 'Personal access token required.'], Response::HTTP_FORBIDDEN);
            }

            if (! $user->can('manageTeam', $workspace)) {
                return response()->json(['message' => 'Insufficient workspace permissions.'], Response::HTTP_FORBIDDEN);
            }
        }

        // Match web access (EnsureAccountReady): Stripe subscription OR generic
        // no-card trial when REQUIRE_CARD_FOR_TRIAL is disabled.
        if (! config('trypost.self_hosted') && ! $workspace->account?->hasAppAccess()) {
            return response()->json(['message' => 'Active subscription required.'], Response::HTTP_PAYMENT_REQUIRED);
        }

        $user->setRelation('currentWorkspace', $workspace);
        $user->current_workspace_id = $workspace->id;

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
