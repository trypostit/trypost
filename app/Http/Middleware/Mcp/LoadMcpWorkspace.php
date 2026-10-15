<?php

declare(strict_types=1);

namespace App\Http\Middleware\Mcp;

use App\Http\Middleware\Api\LoadWorkspaceFromToken;
use App\Support\Mcp\ChatGptMcpClient;
use Closure;
use Illuminate\Http\Request;
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

        return $this->loadWorkspace->handle($request, $next, 'mcp');
    }
}
