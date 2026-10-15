<?php

declare(strict_types=1);

namespace App\Actions\Mcp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ListChatGptEgressRanges
{
    private const CACHE_KEY = 'mcp.chatgpt_egress_ranges';

    /**
     * The IPv4 ranges ChatGPT calls MCP servers from, read from OpenAI's
     * published list once a day. A failed read is retried after ten minutes
     * and answers no ranges, so ChatGPT is then treated like any client.
     *
     * @return list<string>
     */
    public static function execute(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout(3)->get(config('trypost.mcp_hosted_clients.chatgpt.ip_ranges_url'));
        } catch (ConnectionException) {
            $response = null;
        }

        $ranges = $response?->successful()
            ? collect($response->json('prefixes', []))->pluck('ipv4Prefix')->filter()->values()->all()
            : [];

        Cache::put(self::CACHE_KEY, $ranges, $ranges === [] ? now()->addMinutes(10) : now()->addDay());

        return $ranges;
    }
}
