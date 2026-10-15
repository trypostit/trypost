<?php

declare(strict_types=1);

namespace App\Support\Mcp;

use App\Actions\Mcp\ListChatGptEgressRanges;
use App\Enums\Mcp\HostedClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class HostedMcpClient
{
    /**
     * JSON-RPC methods a hosted client may call without a token: connecting
     * and reading the tool list. A tool call always needs a token.
     */
    private const DISCOVERY_METHODS = ['initialize', 'notifications/initialized', 'ping', 'tools/list'];

    /**
     * Passport blanks the Authorization header when it refuses a token, so
     * whether the client sent one is recorded before authentication runs.
     */
    public const TOKEN_SENT = 'mcp.token_sent';

    /**
     * ChatGPT or Claude, told apart by the egress ranges each one publishes,
     * the identification both document. Any other client, local ones
     * included, answers null and keeps the plain OAuth flow.
     */
    public static function identify(Request $request): ?HostedClient
    {
        $ip = (string) $request->ip();

        if (IpUtils::checkIp($ip, ListChatGptEgressRanges::execute())) {
            return HostedClient::ChatGpt;
        }

        if (IpUtils::checkIp($ip, config('trypost.mcp_hosted_clients.claude.ip_ranges'))) {
            return HostedClient::Claude;
        }

        return null;
    }

    /**
     * A hosted client connecting or listing tools before the user signed in.
     * A request that carries a token, valid or not, is always authenticated.
     */
    public static function allowsAnonymousDiscovery(Request $request): bool
    {
        return ! $request->attributes->get(self::TOKEN_SENT, filled($request->bearerToken()))
            && in_array($request->json('method'), self::DISCOVERY_METHODS, true)
            && self::identify($request) !== null;
    }
}
