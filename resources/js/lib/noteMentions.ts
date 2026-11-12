/**
 * Mentions inside a note body: a person is written `@[Display Name](user:{uuid})`
 * and everyone who sees the post `@[word](everyone)`, shown in each reader's
 * language. Mirror of `App\Support\PostNoteMentions`, kept equal by
 * `PostNoteMentionsParityTest`. The composer shows a mention as `@Name` and
 * turns it back into markup when the body is saved.
 */
import { filled, isUuid } from '@/lib/helpers';
import type { MentionedPerson, PostNoteMentionQuery } from '@/types/post-note';

type NoteBodySegment =
    | { kind: 'text'; text: string }
    | { kind: 'mention'; name: string; userId: string }
    | { kind: 'everyone' };

/** The id a picked `@all` carries in the composer; it is never a user id. */
export const EVERYONE_ID = 'everyone';

const MENTION_PATTERN =
    /@\[([^\]\n]{1,255})\]\((?:user:([0-9a-fA-F-]{36})|everyone)\)/gu;

/** A name as it goes inside the markup: no brackets or line breaks. */
const markupName = (name: string): string =>
    name.replace(/[[\]\n]/g, ' ').trim();

/**
 * What may not follow a mention for it to count: a letter (unless Chinese,
 * Japanese or Korean, written without spaces), a digit, `_` or `-`. So
 * `@all-hands` stays text while `@山田さん` is a mention of 山田.
 */
const CONTINUES_NAME =
    '[\\p{N}_-]|[^\\P{L}\\p{Script=Han}\\p{Script=Hiragana}\\p{Script=Katakana}\\p{Script=Hangul}]';

const continuesName = (text: string): boolean =>
    new RegExp(`^(?:${CONTINUES_NAME})`, 'u').test(text);

const escapeRegExp = (text: string): string =>
    text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** How a mention reads in the composer: `@Name`. */
export const mentionLabel = (person: MentionedPerson): string =>
    `@${markupName(person.name)}`;

/** The markup stored for a mention. */
export const mentionMarkup = (person: MentionedPerson): string =>
    person.id === EVERYONE_ID
        ? `@[${markupName(person.name)}](everyone)`
        : `@[${markupName(person.name)}](user:${person.id})`;

/** The body split into text and mentions, in order. */
export const parseNoteBody = (body: string): NoteBodySegment[] => {
    const segments: NoteBodySegment[] = [];
    let last = 0;

    for (const match of body.matchAll(MENTION_PATTERN)) {
        const start = match.index ?? 0;

        if (start > last) {
            segments.push({ kind: 'text', text: body.slice(last, start) });
        }

        segments.push(
            filled(match[2])
                ? { kind: 'mention', name: match[1], userId: match[2] }
                : { kind: 'everyone' },
        );
        last = start + match[0].length;
    }

    if (last < body.length) {
        segments.push({ kind: 'text', text: body.slice(last) });
    }

    return segments;
};

/**
 * The people the body mentions, in order of appearance, a person once per
 * mention; `@all` comes back as `everyoneWord`, the reader's word for it.
 */
export const mentionedPeople = (
    body: string,
    everyoneWord: string,
): MentionedPerson[] =>
    parseNoteBody(body).flatMap((segment): MentionedPerson[] => {
        if (segment.kind === 'everyone') {
            return [{ id: EVERYONE_ID, name: everyoneWord }];
        }

        if (segment.kind === 'mention' && isUuid(segment.userId)) {
            return [{ id: segment.userId.toLowerCase(), name: segment.name }];
        }

        return [];
    });

/** The body as people read it: each mention becomes `@Name`. */
export const plainNoteBody = (body: string, everyoneWord: string): string =>
    body.replace(
        MENTION_PATTERN,
        (_match, name: string, userId: string | undefined) =>
            `@${filled(userId) ? name : everyoneWord}`,
    );

/**
 * The text typed in the composer (where a mention reads `@Name`) as the body
 * to store: each `@Name` of a picked person, not glued to a word before it,
 * becomes their markup. Longer names go first so `@Ana Lima` wins over `@Ana`;
 * people who share a name take its mentions in the order they were mentioned.
 */
export const withMentionMarkup = (
    text: string,
    people: readonly MentionedPerson[],
): string => {
    const byName = new Map<string, MentionedPerson[]>();

    for (const person of people) {
        const name = markupName(person.name);
        const named = byName.get(name) ?? [];

        if (!named.some((known) => known.id === person.id)) {
            byName.set(name, [...named, person]);
        }
    }

    if (byName.size === 0) {
        return text;
    }

    const names = [...byName.keys()]
        .sort((a, b) => b.length - a.length)
        .map(escapeRegExp)
        .join('|');
    const used = new Map<string, number>();

    return text.replace(
        new RegExp(
            `(^|[^\\p{L}\\p{N}_@])@(${names})(?!${CONTINUES_NAME})`,
            'gu',
        ),
        (_match, before: string, name: string) => {
            const named = byName.get(name) ?? [];
            const turn = used.get(name) ?? 0;

            used.set(name, turn + 1);

            return `${before}${mentionMarkup(named[Math.min(turn, named.length - 1)])}`;
        },
    );
};

/**
 * The picked person whose `@Name` the `@` at `start` begins, if any: what
 * follows it is a finished mention rather than a search. The longest name
 * wins, so `@Ana Lima` is Ana Lima even when Ana was picked too.
 */
export const pickedMentionAt = (
    text: string,
    start: number,
    people: readonly MentionedPerson[],
): MentionedPerson | undefined =>
    text.charAt(start) === '@'
        ? [...people]
              .sort(
                  (a, b) =>
                      markupName(b.name).length - markupName(a.name).length,
              )
              .find((person) => {
                  const name = markupName(person.name);

                  return (
                      text.startsWith(name, start + 1) &&
                      !continuesName(text.slice(start + 1 + name.length))
                  );
              })
        : undefined;

/**
 * The `@query` typed right before the caret, if any: an `@` at the start or
 * after a space, followed by at most 40 characters without a line break or
 * another `@`.
 */
export const mentionQueryAt = (
    text: string,
    caret: number,
): PostNoteMentionQuery | null => {
    const match = /(^|\s)@([^@\n]{0,40})$/u.exec(text.slice(0, caret));

    if (!filled(match)) {
        return null;
    }

    return { start: caret - match[2].length - 1, query: match[2] };
};

/** Replaces the `@query` at `start..caret` with the name people read. */
export const insertMention = (
    text: string,
    start: number,
    caret: number,
    person: MentionedPerson,
): { text: string; caret: number } => {
    const label = `@${markupName(person.name)} `;

    return {
        text: text.slice(0, start) + label + text.slice(caret),
        caret: start + label.length,
    };
};
