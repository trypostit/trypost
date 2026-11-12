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
    private const PATTERN = '/@\[([^\]\n]{1,255})\]\(user:([0-9a-fA-F-]{36})\)/u';

    private const EVERYONE = '/@\[[^\]\n]{1,255}\]\(everyone\)/u';

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
        return Str::isMatch(self::EVERYONE, (string) $body);
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
            $person = $people->firstWhere('id', Str::lower($match[2]));

            if (blank($person)) {
                return "@{$match[1]}";
            }

            $name = Str::of($person->name)->replace(['[', ']', "\n"], ' ')->trim();

            return "@[{$name}](user:{$person->id})";
        }, $body);
    }

    /**
     * The body as people read it, in the current locale: each mention becomes
     * `@Name`, and everyone the locale's word for it.
     */
    public static function toPlainText(?string $body): string
    {
        $everyone = __('notes.mention_everyone.word');

        return Str::of((string) $body)
            ->replaceMatches(self::EVERYONE, fn (): string => "@{$everyone}")
            ->replaceMatches(self::PATTERN, '@$1')
            ->value();
    }
}
