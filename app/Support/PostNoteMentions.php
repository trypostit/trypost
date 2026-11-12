<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Mentions inside a note body, by id only: a person is written `@[user:{uuid}]`
 * and everyone who sees the post `@[everyone]`. Names are never stored in the
 * body; they come from the note's `mentions`, and everyone is read in each
 * reader's language. Mirrored by
 * `resources/js/lib/noteMentions.ts` and kept equal by `PostNoteMentionsParityTest`.
 */
final class PostNoteMentions
{
    /** A mention of a person (group 1 holds their uuid) or of everyone (no group 1); the same pattern as `noteMentions.ts`. */
    private const PATTERN = '/@\[(?:user:([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})|everyone)\]/u';

    private const EVERYONE = '@[everyone]';

    /**
     * The unique user ids mentioned in the body, in order of appearance.
     *
     * @return Collection<int, string>
     */
    public static function extract(?string $body): Collection
    {
        return Str::matchAll(self::PATTERN, (string) $body)
            ->filter()
            ->map(fn (string $id): string => Str::lower($id))
            ->unique()
            ->values();
    }

    /**
     * Whether the body mentions everyone who sees the post.
     */
    public static function mentionsEveryone(?string $body): bool
    {
        return Str::contains((string) $body, self::EVERYONE);
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
            function (array $match) use ($people): string {
                $id = data_get($match, 1);

                return blank($id) || $people->contains('id', Str::lower($id)) ? $match[0] : '';
            },
            $body,
        );
    }

    /**
     * The body as people read it: each mention becomes `@Name` from `$people`
     * (`@{$unknown}` when the person is gone), and everyone `@{$everyone}`.
     *
     * @param  Collection<int, User>  $people
     */
    public static function toPlainText(?string $body, Collection $people, string $everyone, string $unknown): string
    {
        return Str::replaceMatches(self::PATTERN, function (array $match) use ($people, $everyone, $unknown): string {
            $name = transform(
                data_get($match, 1),
                fn (string $id): string => $people->firstWhere('id', Str::lower($id))?->name ?? $unknown,
                $everyone,
            );

            return "@{$name}";
        }, (string) $body);
    }
}
