<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

trait AuthorizesMcpTool
{
    /**
     * Mirror web policies inside MCP tools. Returns an error response when denied.
     */
    protected function denyUnlessCan(
        Request $request,
        string $ability,
        mixed $arguments,
        string $message,
    ): Response|ResponseFactory|null {
        $user = $request->user();

        if ($user === null || $user->cannot($ability, $arguments)) {
            return Response::error($message);
        }

        return null;
    }
}
