<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Mentions inside a note body, written as `@[Display Name](user:{uuid})`.
 * Mirrored by `resources/js/lib/noteMentions.ts` and kept equal by
 * `PostNoteMentionsParityTest`.
 */
final class PostNoteMentions
{
    private const PATTERN = '/@\[([^\]\n]{1,255})\]\(user:([0-9a-fA-F-]{36})\)/u';

    /**
     * The unique user ids mentioned in the body, in order of appearance.
     * Markup whose id is not a uuid names nobody and is left out.
     *
     * @return Collection<int, string>
     */
    public static function extract(?string $body): Collection
    {
        preg_match_all(self::PATTERN, (string) $body, $matches);

        return collect($matches[2])
            ->filter(fn (string $id): bool => Str::isUuid($id))
            ->map(fn (string $id): string => Str::lower($id))
            ->unique()
            ->values();
    }

    /**
     * The body as people read it: each mention becomes `@Name`.
     */
    public static function toPlainText(?string $body): string
    {
        return Str::replaceMatches(self::PATTERN, '@$1', (string) $body);
    }
}
