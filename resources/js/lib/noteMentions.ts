/**
 * Mentions inside a note body, by id only: a person is written `@[user:{uuid}]`
 * and everyone who sees the post `@[everyone]`. Names never live in the body;
 * the note's `mentions` carry them. Mirror of `App\Support\PostNoteMentions`,
 * kept equal by `PostNoteMentionsParityTest`. The note editor loads a body
 * through `noteBodyToDoc` and stores it through the `NoteBody` TipTap plugin.
 */
import type { JSONContent } from '@tiptap/core';

import { filled, isEmpty } from '@/lib/helpers';

type NoteBodySegment =
    | { kind: 'text'; text: string }
    | { kind: 'mention'; userId: string }
    | { kind: 'everyone' };

/** The id a mention of everyone carries in the editor; it is never a user id. */
export const EVERYONE_ID = 'everyone';

/** A mention of a person (group 1 holds their uuid) or of everyone. */
export const MENTION_PATTERN =
    /@\[(?:user:([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})|everyone)\]/gu;

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
            filled(match[1])
                ? { kind: 'mention', userId: match[1].toLowerCase() }
                : { kind: 'everyone' },
        );
        last = start + match[0].length;
    }

    if (last < body.length) {
        segments.push({ kind: 'text', text: body.slice(last) });
    }

    return segments;
};

/** Text as the editor holds it: a line break for each new line. */
const textNodes = (text: string): JSONContent[] =>
    text
        .split('\n')
        .flatMap((line, index): JSONContent[] => [
            ...(index > 0 ? [{ type: 'hardBreak' }] : []),
            ...(isEmpty(line) ? [] : [{ type: 'text', text: line }]),
        ]);

/**
 * A stored body as the editor's document: one paragraph with a mention node
 * for each mention, labelled by `label(id)` (`EVERYONE_ID` for everyone).
 */
export const noteBodyToDoc = (
    body: string,
    label: (id: string) => string,
): JSONContent => ({
    type: 'doc',
    content: [
        {
            type: 'paragraph',
            content: parseNoteBody(body).flatMap((segment): JSONContent[] => {
                if (segment.kind === 'text') {
                    return textNodes(segment.text);
                }

                const id =
                    segment.kind === 'everyone' ? EVERYONE_ID : segment.userId;

                return [{ type: 'mention', attrs: { id, label: label(id) } }];
            }),
        },
    ],
});
