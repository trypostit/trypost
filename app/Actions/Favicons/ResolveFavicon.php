<?php

declare(strict_types=1);

namespace App\Actions\Favicons;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resolves a site's favicon through the configured provider, falling back to a
 * neutral globe. Proxying the lookup server-side keeps the browser from
 * leaking every domain it shows to a third party.
 */
class ResolveFavicon
{
    private const int CACHE_TTL = 60 * 60 * 24 * 30;

    private const int TIMEOUT = 5;

    private const int MIN_ICON_BYTES = 100;

    private const string DEFAULT_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>';

    /**
     * @return array{body: string, contentType: string}
     */
    public static function execute(string $domain): array
    {
        $domain = self::normalize($domain);

        if ($domain === '' || preg_match('/^[a-z0-9.\-]+$/', $domain) !== 1) {
            return self::default();
        }

        $cacheKey = "favicon:{$domain}";
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return [
                'body' => base64_decode(data_get($cached, 'body')),
                'contentType' => data_get($cached, 'contentType'),
            ];
        }

        [$resolved, $cacheable] = self::fetch($domain);

        if ($cacheable) {
            Cache::put($cacheKey, [
                'body' => base64_encode($resolved['body']),
                'contentType' => $resolved['contentType'],
            ], self::CACHE_TTL);
        }

        return $resolved;
    }

    /**
     * Transient failures are served uncached so the next request retries; a
     * definitive miss is cached like a hit.
     *
     * @return array{0: array{body: string, contentType: string}, 1: bool}
     */
    private static function fetch(string $domain): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)->get(config('trypost.favicon.api')."/ip3/{$domain}.ico");
        } catch (Throwable) {
            return [self::default(), false];
        }

        if ($response->serverError() || $response->status() === 429) {
            return [self::default(), false];
        }

        $body = $response->body();

        if ($response->successful() && mb_strlen($body, '8bit') > self::MIN_ICON_BYTES) {
            return [['body' => $body, 'contentType' => self::contentType($response->header('Content-Type'), $body)], true];
        }

        return [self::default(), true];
    }

    private static function contentType(?string $upstream, string $body): string
    {
        if (str_starts_with($body, '<svg') || str_starts_with($body, '<?xml')) {
            return 'image/svg+xml';
        }

        return filled($upstream) ? $upstream : 'image/x-icon';
    }

    /**
     * @return array{body: string, contentType: string}
     */
    private static function default(): array
    {
        return [
            'body' => self::DEFAULT_ICON,
            'contentType' => 'image/svg+xml',
        ];
    }

    private static function normalize(string $domain): string
    {
        $domain = mb_strtolower(trim($domain));
        $domain = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $domain) ?? $domain;

        return explode('/', $domain)[0];
    }
}
