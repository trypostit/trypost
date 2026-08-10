<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Models\Workspace;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

trait AuthorizesMcpTool
{
    /**
     * Mirror web policies inside MCP tools. Returns an error response when denied.
     * Fails closed when the request has no user or when $arguments is null.
     */
    protected function denyUnlessCan(
        Request $request,
        string $ability,
        mixed $arguments,
        string $message,
    ): Response|ResponseFactory|null {
        $user = $request->user();

        if ($user === null || $arguments === null || $user->cannot($ability, $arguments)) {
            return Response::error($message);
        }

        return null;
    }

    /**
     * Authorize a workspace-level ability against the current workspace.
     * Resolves the workspace via nullsafe access so missing auth never TypeErrors.
     */
    protected function authorizeCurrentWorkspace(
        Request $request,
        string $ability,
        string $message,
    ): Workspace|Response|ResponseFactory {
        $workspace = $request->user()?->currentWorkspace;

        if ($denied = $this->denyUnlessCan($request, $ability, $workspace, $message)) {
            return $denied;
        }

        assert($workspace instanceof Workspace);

        return $workspace;
    }
}
