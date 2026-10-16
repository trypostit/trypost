<?php

declare(strict_types=1);

namespace App\Support\Mcp;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class HostedMcpClient
{
    /**
     * ChatGPT or Claude, told by the egress ranges OpenAI and Anthropic
     * publish. Each calls for all of its users from a few shared addresses,
     * so a limit per IP would hold every one of them to one budget.
     */
    public static function matches(Request $request): bool
    {
        return ChatGptMcpClient::matches($request)
            || IpUtils::checkIp((string) $request->ip(), config()->array('trypost.mcp_claude_ip_ranges'));
    }
}
