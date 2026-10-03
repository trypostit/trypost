<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Hashtags as Instagram links them: `#` at the start or after a character
 * that cannot be part of a word, URL path or HTML entity, followed by
 * letters, digits or `_` with at least one letter. Mirrored by
 * `resources/js/lib/hashtags.ts`; keep the two patterns identical.
 */
final class Hashtags
{
    public const string PATTERN = '/(?:^|[^\p{L}\p{N}_&#\/])#(?=[\p{N}_]*\p{L})[\p{L}\p{N}_]+/u';

    public static function count(string $text): int
    {
        return Str::matchAll(self::PATTERN, $text)->count();
    }

    /**
     * Drops every hashtag after the first `$limit`, with the space before it.
     */
    public static function keepFirst(string $text, int $limit): string
    {
        $seen = 0;

        return (string) preg_replace_callback(self::PATTERN, function (array $match) use (&$seen, $limit): string {
            if (++$seen <= $limit) {
                return $match[0];
            }

            $prefix = Str::before($match[0], '#');

            return preg_match('/^\h$/u', $prefix) === 1 ? '' : $prefix;
        }, $text);
    }
}
