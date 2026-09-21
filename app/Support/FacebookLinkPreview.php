<?php

declare(strict_types=1);

namespace App\Support;

/**
 * First http(s) URL Facebook will accept as a Page feed `link`. facebook.com,
 * fb.com and fb.me (and their subdomains) are skipped: the API rejects many of
 * them and fails the whole post. The editor mirrors this in
 * `resources/js/lib/facebookLinkPreview.ts`; the parity test keeps the two
 * in step.
 */
final class FacebookLinkPreview
{
    public static function url(string $text): ?string
    {
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length && preg_match(UrlDetector::URL_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $raw = $matches[0][0];
            $url = UrlDetector::trimTrailingPunctuation($raw);

            if (! self::isOwnedHost($url)) {
                return $url;
            }

            $offset = $matches[0][1] + strlen($raw);
        }

        return null;
    }

    private static function isOwnedHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);

        foreach (['facebook.com', 'fb.com', 'fb.me'] as $domain) {
            if ($host === $domain || str_ends_with($host, ".{$domain}")) {
                return true;
            }
        }

        return false;
    }
}
