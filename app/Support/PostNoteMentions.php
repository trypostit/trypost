<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Mentions inside a note body: a person is written `@[Display Name](user:{uuid})`
 * and everyone who sees the post `@[word](everyone)`, read in each reader's
 * language (`notes.mention_everyone.word`). Mirrored by
 * `resources/js/lib/noteMentions.ts` and kept equal by `PostNoteMentionsParityTest`.
 */
final class PostNoteMentions
{
    /** A mention of a person (group 2 holds their id) or of everyone (no group 2); the same pattern as `noteMentions.ts`. */
    private const PATTERN = '/@\[([^\]\n]{1,255})\]\((?:user:([0-9a-fA-F-]{36})|everyone)\)/u';

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
     * Whether the body mentions everyone who sees the post.
     */
    public static function mentionsEveryone(?string $body): bool
    {
        preg_match_all(self::PATTERN, (string) $body, $matches);

        return collect($matches[2])->contains(fn (string $id): bool => blank($id));
    }

    /**
     * The body with each mention naming the person as they are called now,
     * so a note cannot show a name other than theirs. A mention of someone
     * outside `$people` becomes plain text.
     *
     * @param  Collection<int, User>  $people
     */
    public static function withCurrentNames(string $body, Collection $people): string
    {
        return Str::replaceMatches(self::PATTERN, function (array $match) use ($people): string {
            if (blank(data_get($match, 2))) {
                return $match[0];
            }

            $person = $people->firstWhere('id', Str::lower($match[2]));

            if (blank($person)) {
                return "@{$match[1]}";
            }

            $name = self::markupName($person->name);

            return "@[{$name}](user:{$person->id})";
        }, $body);
    }

    /**
     * A name as it goes inside the markup: brackets become spaces, whitespace is
     * squished, and a name left empty reads `?`. Same rule as `markupName()` in
     * `noteMentions.ts`.
     */
    public static function markupName(string $name): string
    {
        $name = Str::squish(Str::replace(['[', ']'], ' ', $name));

        return filled($name) ? $name : '?';
    }

    /**
     * The body as people read it, in the current locale: each mention becomes
     * `@Name`, and everyone the locale's word for it.
     */
    public static function toPlainText(?string $body): string
    {
        $everyone = __('notes.mention_everyone.word');

        return Str::replaceMatches(
            self::PATTERN,
            fn (array $match): string => filled(data_get($match, 2)) ? "@{$match[1]}" : "@{$everyone}",
            (string) $body,
        );
    }
}
