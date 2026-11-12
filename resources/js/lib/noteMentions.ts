/**
 * Mentions inside a note body, written as `@[Display Name](user:{uuid})`.
 * Mirror of `App\Support\PostNoteMentions`, kept equal by
 * `PostNoteMentionsParityTest`. The composer shows a mention as `@Name` and
 * turns it back into markup when the body is saved.
 */
import { filled } from '@/lib/helpers';

export type NoteBodySegment =
    | { kind: 'text'; text: string }
    | { kind: 'mention'; name: string; userId: string };

export type MentionedPerson = { id: string; name: string };

const MENTION_PATTERN = /@\[([^\]\n]{1,255})\]\(user:([0-9a-fA-F-]{36})\)/gu;

const UUID_PATTERN =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** A name as it goes inside the markup: no brackets or line breaks. */
const markupName = (name: string): string =>
    name.replace(/[[\]\n]/g, ' ').trim();

const escapeRegExp = (text: string): string =>
    text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** The markup stored for a mention. */
export const mentionMarkup = (person: MentionedPerson): string =>
    `@[${markupName(person.name)}](user:${person.id})`;

/** The body split into text and mentions, in order. */
export const parseNoteBody = (body: string): NoteBodySegment[] => {
    const segments: NoteBodySegment[] = [];
    let last = 0;

    for (const match of body.matchAll(MENTION_PATTERN)) {
        const start = match.index ?? 0;

        if (start > last) {
            segments.push({ kind: 'text', text: body.slice(last, start) });
        }

        segments.push({ kind: 'mention', name: match[1], userId: match[2] });
        last = start + match[0].length;
    }

    if (last < body.length) {
        segments.push({ kind: 'text', text: body.slice(last) });
    }

    return segments;
};

/** The people the body mentions, once each, in order of first mention. */
export const mentionedPeople = (body: string): MentionedPerson[] => {
    const people = new Map<string, MentionedPerson>();

    for (const segment of parseNoteBody(body)) {
        if (
            segment.kind === 'mention' &&
            UUID_PATTERN.test(segment.userId) &&
            !people.has(segment.userId.toLowerCase())
        ) {
            people.set(segment.userId.toLowerCase(), {
                id: segment.userId.toLowerCase(),
                name: segment.name,
            });
        }
    }

    return [...people.values()];
};

/** The body as people read it: each mention becomes `@Name`. */
export const plainNoteBody = (body: string): string =>
    body.replace(MENTION_PATTERN, (_match, name: string) => `@${name}`);

/**
 * The text typed in the composer (where a mention reads `@Name`) as the body
 * to store: each `@Name` of a picked person, standing alone, becomes their
 * markup. Longer names go first so `@Ana Lima` wins over `@Ana`.
 */
export const withMentionMarkup = (
    text: string,
    people: readonly MentionedPerson[],
): string =>
    [...people]
        .sort((a, b) => markupName(b.name).length - markupName(a.name).length)
        .reduce(
            (body, person) =>
                body.replace(
                    new RegExp(
                        `(^|\\s)@${escapeRegExp(markupName(person.name))}(?![\\p{L}\\p{N}_])`,
                        'gu',
                    ),
                    (_match, before: string) =>
                        `${before}${mentionMarkup(person)}`,
                ),
            text,
        );

/**
 * Whether the `@` at `start` begins the `@Name` of a picked person, so what
 * follows it is a finished mention and not a search.
 */
export const isPickedMentionAt = (
    text: string,
    start: number,
    people: readonly MentionedPerson[],
): boolean =>
    text.charAt(start) === '@' &&
    people.some((person) => {
        const name = markupName(person.name);

        return (
            text.startsWith(name, start + 1) &&
            !/^[\p{L}\p{N}_]/u.test(text.slice(start + 1 + name.length))
        );
    });

/**
 * The `@query` typed right before the caret, if any: an `@` at the start or
 * after a space, followed by at most 40 characters without a line break or
 * another `@`.
 */
export const mentionQueryAt = (
    text: string,
    caret: number,
): { start: number; query: string } | null => {
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
