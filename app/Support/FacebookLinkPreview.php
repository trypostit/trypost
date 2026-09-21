<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * First http(s) URL Facebook will accept as a Page feed `link`. facebook.com,
 * fb.com and fb.me (and their subdomains) are skipped: the API rejects many of
 * them and fails the whole post. The editor mirrors this in
 * `resources/js/lib/facebookLinkPreview.ts`; the parity test keeps the two
 * in step.
 */
final class FacebookLinkPreview
{
    /**
     * @var list<string>
     */
    private const array OWNED_HOSTS = [
        'facebook.com',
        '*.facebook.com',
        'fb.com',
        '*.fb.com',
        'fb.me',
        '*.fb.me',
    ];

    public static function url(string $text): ?string
    {
        return Str::matchAll(UrlDetector::URL_PATTERN, $text)
            ->map(fn (string $raw): string => UrlDetector::trimTrailingPunctuation($raw))
            ->first(fn (string $candidate): bool => ! self::isOwnedHost($candidate));
    }

    private static function isOwnedHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        return Str::is(self::OWNED_HOSTS, $host, ignoreCase: true);
    }
}
