<?php

declare(strict_types=1);

namespace App\Support\Mcp;

use App\Actions\Mcp\ListChatGptEgressRanges;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class ChatGptMcpClient
{
    /**
     * JSON-RPC methods ChatGPT may call without a token: connecting and
     * reading the tool list. A tool call always needs a token.
     */
    private const DISCOVERY_METHODS = ['initialize', 'notifications/initialized', 'ping', 'tools/list'];

    /**
     * Passport blanks the Authorization header when it refuses a token, so
     * whether the client sent one is recorded before authentication runs.
     */
    public const TOKEN_SENT = 'mcp.token_sent';

    /**
     * ChatGPT, told by the egress ranges OpenAI publishes, the identification
     * OpenAI documents. Any other client, Claude and local ones included,
     * keeps the plain OAuth flow.
     */
    public static function matches(Request $request): bool
    {
        return IpUtils::checkIp((string) $request->ip(), ListChatGptEgressRanges::execute());
    }

    /**
     * ChatGPT connecting or listing tools before the user signed in. A
     * request that carries a token, valid or not, is always authenticated.
     */
    public static function allowsAnonymousDiscovery(Request $request): bool
    {
        return ! $request->attributes->get(self::TOKEN_SENT, filled($request->bearerToken()))
            && in_array($request->json('method'), self::DISCOVERY_METHODS, true)
            && self::matches($request);
    }
}
