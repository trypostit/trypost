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
 * reader's language. This is the one place that reads the markup: the app gets
 * a note split into parts (`parts()`), and the note editor writes the markup
 * back through TipTap (`components/tiptap/plugins/noteBody.ts`).
 */
final class PostNoteMentions
{
    /** A mention of a person (group 1 holds their uuid) or of everyone (no group 1). */
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

                return blank($id) || $people->contains('id', Str::lower($id)) ? data_get($match, 0) : '';
            },
            $body,
        );
    }

    /**
     * The body split into its text and its mentions, in order, each mention of
     * a person named from `$people` (null when the person is gone), so the app
     * shows and edits a note without reading the markup itself.
     *
     * @param  Collection<int, User>  $people
     * @return list<array{type: 'text', text: string}|array{type: 'mention', id: string, name: string|null}|array{type: 'everyone'}>
     */
    public static function parts(string $body, Collection $people): array
    {
        preg_match_all(self::PATTERN, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $parts = [];
        $position = 0;

        foreach ($matches as $match) {
            [$markup, $offset] = data_get($match, 0);
            $id = transform(data_get($match, '1.0'), fn (string $id): string => Str::lower($id));

            if ($offset > $position) {
                $parts[] = ['type' => 'text', 'text' => substr($body, $position, $offset - $position)];
            }

            $parts[] = blank($id)
                ? ['type' => 'everyone']
                : ['type' => 'mention', 'id' => $id, 'name' => $people->firstWhere('id', $id)?->name];
            $position = $offset + strlen($markup);
        }

        if ($position < strlen($body)) {
            $parts[] = ['type' => 'text', 'text' => substr($body, $position)];
        }

        return $parts;
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
