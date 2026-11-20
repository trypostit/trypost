<?php

declare(strict_types=1);

namespace App\Actions\Mcp;

use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ListChatGptEgressRanges
{
    private const CACHE_KEY = 'mcp.chatgpt_egress_ranges';

    private const TIMEOUT_SECONDS = 3;

    /**
     * The widest range kept from OpenAI's list: a wider one would take
     * unrelated clients for ChatGPT.
     */
    private const MIN_PREFIX_LENGTH = 8;

    private const MAX_PREFIX_LENGTH = 32;

    /**
     * The IPv4 ranges ChatGPT calls MCP servers from, read from OpenAI's
     * published list and kept for an hour. A failed read answers no ranges,
     * so ChatGPT is treated like any client, and is retried after a minute.
     *
     * @return list<string>
     */
    public static function execute(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            fn (array $ranges): DateTimeInterface => filled($ranges) ? now()->addHour() : now()->addMinute(),
            fn (): array => self::read(),
        );
    }

    /**
     * @return list<string>
     */
    private static function read(): array
    {
        $prefixes = rescue(
            fn (): mixed => Http::timeout(self::TIMEOUT_SECONDS)
                ->get(config('trypost.mcp_chatgpt_ip_ranges_url'))
                ->throw()
                ->json('prefixes', []),
            [],
            report: false,
        );

        return collect($prefixes)
            ->pluck('ipv4Prefix')
            ->filter(self::isValidRange(...))
            ->values()
            ->all();
    }

    private static function isValidRange(mixed $range): bool
    {
        if (! is_string($range)) {
            return false;
        }

        return filter_var(Str::before($range, '/'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && filter_var(Str::after($range, '/'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => self::MIN_PREFIX_LENGTH, 'max_range' => self::MAX_PREFIX_LENGTH],
            ]) !== false;
    }
}
