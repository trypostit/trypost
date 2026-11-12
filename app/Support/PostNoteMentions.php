<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Mentions inside a note body, by id only: a person is written `@[user:{uuid}]`
 * and everyone who sees the post `@[everyone]`. Names are never stored in the
 * body; they come from the note's `mentions` and are read in each reader's
 * language for everyone (`notes.mention_everyone.word`). Mirrored by
 * `resources/js/lib/noteMentions.ts` and kept equal by `PostNoteMentionsParityTest`.
 */
final class PostNoteMentions
{
    /** A mention of a person (group 1 holds their id) or of everyone (no group 1); the same pattern as `noteMentions.ts`. */
    private const PATTERN = '/@\[(?:user:([0-9a-fA-F-]{36})|everyone)\]/u';

    /**
     * The unique user ids mentioned in the body, in order of appearance.
     * Markup whose id is not a uuid names nobody and is left out.
     *
     * @return Collection<int, string>
     */
    public static function extract(?string $body): Collection
    {
        preg_match_all(self::PATTERN, (string) $body, $matches);

        return collect($matches[1])
            ->filter(fn (string $id): bool => Str::isUuid($id))
            ->map(fn (string $id): string => Str::lower($id))
            ->unique()
            ->values();
    }

    /**
     * Whether the body mentions everyone who sees the post.
     */
    public static function mentionsEveryone(?string $body): bool
    {
        preg_match_all(self::PATTERN, (string) $body, $matches);

        return collect($matches[1])->contains(fn (string $id): bool => blank($id));
    }

    /**
     * The body without its mentions of anyone outside `$people` (the members who
     * see the post), so a note never names someone it cannot reach.
     *
     * @param  Collection<int, User>  $people
     */
    public static function withoutMentionsOutside(string $body, Collection $people): string
    {
        return Str::replaceMatches(
            self::PATTERN,
            fn (array $match): string => blank(data_get($match, 1)) || $people->contains('id', Str::lower($match[1])) ? $match[0] : '',
            $body,
        );
    }

    /**
     * The body as people read it, in the current locale: each mention becomes
     * `@Name` from `$people` (the word for someone no longer there when the
     * person is gone), and everyone the locale's word for it.
     *
     * @param  Collection<int, User>  $people
     */
    public static function toPlainText(?string $body, Collection $people): string
    {
        $everyone = __('notes.mention_everyone.word');
        $unknown = __('notes.mention_unknown');

        return Str::replaceMatches(self::PATTERN, function (array $match) use ($people, $everyone, $unknown): string {
            if (blank(data_get($match, 1))) {
                return "@{$everyone}";
            }

            $name = $people->firstWhere('id', Str::lower($match[1]))?->name ?? $unknown;

            return "@{$name}";
        }, (string) $body);
    }
}
