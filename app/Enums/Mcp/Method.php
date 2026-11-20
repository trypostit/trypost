<?php

declare(strict_types=1);

namespace App\Enums\Mcp;

/**
 * The JSON-RPC methods of the MCP protocol the app looks at. laravel/mcp keeps
 * its own list in a protected array, so there is no constant to compare with.
 */
enum Method: string
{
    case Initialize = 'initialize';
    case NotificationsInitialized = 'notifications/initialized';
    case Ping = 'ping';
    case ToolsList = 'tools/list';
    case ToolsCall = 'tools/call';
}
