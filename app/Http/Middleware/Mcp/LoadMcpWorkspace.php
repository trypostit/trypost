<?php

declare(strict_types=1);

namespace App\Http\Middleware\Mcp;

use App\Enums\AccessToken\McpClient;
use App\Enums\PostHog\McpEvent;
use App\Http\Middleware\Api\LoadWorkspaceFromToken;
use App\Jobs\PostHog\CaptureAccountEvent;
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
            $this->trackToolCalled($request);

            return $next($request);
        }, 'mcp');
    }

    private function trackToolCalled(Request $request): void
    {
        if (! PostHogService::isEnabled() || $request->input('method') !== 'tools/call') {
            return;
        }

        rescue(function () use ($request): void {
            $user = $request->user();
            $workspace = $user->currentWorkspace;
            $today = now()->utc();

            if (! Cache::add("posthog:mcp_tool_called:{$user->id}:{$today->toDateString()}", true, $today->endOfDay())) {
                return;
            }

            $client = $request->attributes->get('access_token')?->loadMissing('client')->client;

            CaptureAccountEvent::dispatch(
                (string) $user->id,
                (string) $workspace->account_id,
                McpEvent::ToolCalled,
                [
                    'tool' => $request->input('params.name'),
                    'client' => McpClient::fromClientName((string) $client?->name)->value,
                    'workspace_id' => (string) $workspace->id,
                ],
            );
        });
    }
}
