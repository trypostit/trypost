<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum McpEvent: string
{
    case Connected = 'mcp.connected';
    case ToolCalled = 'mcp.tool_called';
}
