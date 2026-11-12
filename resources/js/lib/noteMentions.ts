/**
 * Mentions inside a note body: a person is written `@[Display Name](user:{uuid})`
 * and everyone who sees the post `@[word](everyone)`, shown in each reader's
 * language. Mirror of `App\Support\PostNoteMentions`, kept equal by
 * `PostNoteMentionsParityTest`. The note editor holds mentions as nodes and
 * reads and writes this markup through `noteBodyToDoc` / `docToNoteBody`.
 */
import type { JSONContent } from '@tiptap/core';

import { filled, isEmpty, isUuid } from '@/lib/helpers';
import type { MentionedPerson } from '@/types/post-note';

type NoteBodySegment =
    | { kind: 'text'; text: string }
    | { kind: 'mention'; name: string; userId: string }
    | { kind: 'everyone' };

/** The id a mention of everyone carries in the editor; it is never a user id. */
export const EVERYONE_ID = 'everyone';

const MENTION_PATTERN =
    /@\[([^\]\n]{1,255})\]\((?:user:([0-9a-fA-F-]{36})|everyone)\)/gu;

/**
 * A name as it goes inside the markup: brackets become spaces, whitespace is
 * squished, and a name left empty reads `?`.
 */
export const markupName = (name: string): string =>
    name.replace(/[[\]]/g, ' ').replace(/\s+/g, ' ').trim() || '?';

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
 * A stored body as the editor's document: one paragraph, a line break for
 * each new line, a mention node for each mention (everyone labelled with the
 * reader's word). Markup naming no valid id stays text.
 */
export const noteBodyToDoc = (
    body: string,
    everyoneWord: string,
): JSONContent => ({
    type: 'doc',
    content: [
        {
            type: 'paragraph',
            content: parseNoteBody(body).flatMap((segment): JSONContent[] => {
                if (segment.kind === 'everyone') {
                    return [
                        {
                            type: 'mention',
                            attrs: { id: EVERYONE_ID, label: everyoneWord },
                        },
                    ];
                }

                if (segment.kind === 'mention' && isUuid(segment.userId)) {
                    return [
                        {
                            type: 'mention',
                            attrs: {
                                id: segment.userId.toLowerCase(),
                                label: segment.name,
                            },
                        },
                    ];
                }

                const text =
                    segment.kind === 'text'
                        ? segment.text
                        : `@[${segment.name}](user:${segment.userId})`;

                return text
                    .split('\n')
                    .flatMap((line, index): JSONContent[] => [
                        ...(index > 0 ? [{ type: 'hardBreak' }] : []),
                        ...(isEmpty(line)
                            ? []
                            : [{ type: 'text', text: line }]),
                    ]);
            }),
        },
    ],
});

/** The editor's document as the body to store: paragraphs and line breaks become new lines. */
export const docToNoteBody = (doc: JSONContent): string =>
    (doc.content ?? [])
        .map((paragraph) =>
            (paragraph.content ?? [])
                .map((node) => {
                    if (node.type === 'mention') {
                        return mentionMarkup({
                            id: String(node.attrs?.id),
                            name: String(node.attrs?.label ?? ''),
                        });
                    }

                    return node.type === 'hardBreak' ? '\n' : (node.text ?? '');
                })
                .join(''),
        )
        .join('\n');
