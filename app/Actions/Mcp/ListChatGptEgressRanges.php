<?php

declare(strict_types=1);

namespace App\Actions\Mcp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ListChatGptEgressRanges
{
    private const CACHE_KEY = 'mcp.chatgpt_egress_ranges';

    private const TIMEOUT_SECONDS = 3;

    /**
     * The narrowest prefix length kept from OpenAI's list: a wider range
     * would take unrelated clients for ChatGPT.
     */
    private const MIN_PREFIX_LENGTH = 8;

    /**
     * The IPv4 ranges ChatGPT calls MCP servers from, read from OpenAI's
     * published list and kept for an hour. A failed read answers no ranges,
     * so ChatGPT is treated like any client, and is retried after a minute.
     *
     * @return list<string>
     */
    public static function execute(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $ranges = self::read();

        Cache::put(self::CACHE_KEY, $ranges, filled($ranges) ? now()->addHour() : now()->addMinute());

        return $ranges;
    }

    /**
     * @return list<string>
     */
    private static function read(): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->get(config('trypost.mcp_hosted_clients.chatgpt.ip_ranges_url'));
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('prefixes', []))
            ->pluck('ipv4Prefix')
            ->filter(fn (mixed $range): bool => self::isValidRange($range))
            ->values()
            ->all();
    }

    private static function isValidRange(mixed $range): bool
    {
        if (! is_string($range) || ! preg_match('#^([0-9.]+)/([0-9]{1,2})$#', $range, $parts)) {
            return false;
        }

        return filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && (int) $parts[2] >= self::MIN_PREFIX_LENGTH
            && (int) $parts[2] <= 32;
    }
}
