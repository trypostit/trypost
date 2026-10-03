<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\DomCrawler\UriResolver;

final class RssFeedUrl
{
    public static function identity(string $url): string
    {
        $parts = parse_url(trim($url));

        if ($parts === false) {
            return trim($url);
        }

        $scheme = strtolower((string) data_get($parts, 'scheme', 'https'));
        $host = rtrim(strtolower((string) data_get($parts, 'host', '')), '.');
        $port = data_get($parts, 'port');
        $defaultPort = $scheme === 'http' ? 80 : 443;
        $authority = $port === null || (int) $port === $defaultPort ? $host : "{$host}:{$port}";

        $path = (string) data_get($parts, 'path', '');
        $resolvedPath = (string) parse_url(UriResolver::resolve('./'.ltrim($path, '/'), 'https://x/'), PHP_URL_PATH);
        $resolvedPath = $resolvedPath === '' ? '/' : $resolvedPath;

        if ($resolvedPath !== '/') {
            $resolvedPath = rtrim($resolvedPath, '/');
            $resolvedPath = $resolvedPath === '' ? '/' : $resolvedPath;
        }

        $query = data_get($parts, 'query');

        return $query === null || $query === '' ? "{$authority}{$resolvedPath}" : "{$authority}{$resolvedPath}?{$query}";
    }

    public static function hash(string $url): string
    {
        return hash('sha256', self::identity($url));
    }
}
