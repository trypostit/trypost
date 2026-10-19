<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Enums\AccessToken\McpClient;
use App\Enums\PostHog\McpEvent;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        // Personal API keys and MCP OAuth grants both bind to a workspace at
        // issue time. Resolve from the token — never from the user's current
        // workspace switcher (that would let a multi-workspace agent silently
        // act on the wrong tenant).
        $workspace = $token->workspace_id
            ? Workspace::query()->find($token->workspace_id)
            : null;

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

        if ($context === 'mcp') {
            rescue(fn () => $this->trackToolCalled($request, $user, $token, $workspace));
        }

        return $next($request);
    }

    private function trackToolCalled(Request $request, User $user, AccessToken $token, Workspace $workspace): void
    {
        if (! PostHogService::isEnabled() || $request->input('method') !== 'tools/call') {
            return;
        }

        $today = now()->utc();

        if (! Cache::add("posthog:mcp_tool_called:{$user->id}:{$today->toDateString()}", true, $today->endOfDay())) {
            return;
        }

        $token->loadMissing('client');

        CaptureAccountEvent::dispatch(
            (string) $user->id,
            (string) $workspace->account_id,
            McpEvent::ToolCalled->value,
            [
                'tool' => $request->input('params.name'),
                'client' => McpClient::fromClientName($token->client->name)->value,
                'workspace_id' => (string) $workspace->id,
            ],
        );
    }
}
