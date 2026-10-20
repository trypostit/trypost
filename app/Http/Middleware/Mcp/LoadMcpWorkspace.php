<?php

declare(strict_types=1);

namespace App\Http\Middleware\Mcp;

use App\Enums\Mcp\Method;
use App\Http\Middleware\Api\LoadWorkspaceFromToken;
use App\Jobs\PostHog\TrackMcpToolCalled;
use App\Services\PostHogService;
use App\Support\Mcp\ChatGptMcpClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class LoadMcpWorkspace
{
    public function __construct(private readonly LoadWorkspaceFromToken $loadWorkspace) {}

    /**
     * ChatGPT connecting or listing tools before the user signs in has no
     * token and so no workspace; every other MCP request loads the workspace
     * its token was issued for, like the API does.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (blank($request->user()) && ChatGptMcpClient::allowsAnonymousDiscovery($request)) {
            return $next($request);
        }

        return $this->loadWorkspace->handle($request, function (Request $request) use ($next): Response {
            if ($request->enum('method', Method::class) === Method::ToolsCall) {
                rescue(function () use ($request): void {
                    $user = $request->user();
                    $today = now()->utc();

                    TrackMcpToolCalled::dispatchIf(
                        PostHogService::isEnabled() && Cache::add("posthog:mcp_tool_called:{$user->id}:{$today->toDateString()}", true, $today->endOfDay()),
                        (string) $user->id,
                        (string) $user->current_workspace_id,
                        $user->token()?->oauth_access_token_id,
                        $request->input('params.name'),
                    );
                });
            }

            return $next($request);
        }, 'mcp');
    }
}
