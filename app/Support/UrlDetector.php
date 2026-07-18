<?php

declare(strict_types=1);

namespace App\Support;

final class UrlDetector
{
    /** PCRE matching bare http(s) URLs; shared with Bluesky link-facet parsing. */
    public const string URL_PATTERN = '/(https?:\/\/[^\s]+)/u';

    public static function firstUrl(string $text): ?string
    {
        if (preg_match(self::URL_PATTERN, $text, $matches) !== 1) {
            return null;
        }

        return self::trimTrailingPunctuation($matches[0]);
    }

    /**
     * Trailing sentence punctuation and an unmatched closing paren are almost
     * never part of a URL (e.g. "see https://x.com)."). Mirrors the official
     * atproto link tokenizer.
     */
    public static function trimTrailingPunctuation(string $url): string
    {
        if (preg_match('/[.,;:!?]$/', $url)) {
            $url = substr($url, 0, -1);
        }

        if (str_ends_with($url, ')') && ! str_contains($url, '(')) {
            $url = substr($url, 0, -1);
        }

        return $url;
    }
}
