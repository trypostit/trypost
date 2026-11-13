/**
 * A note as the app gets it: its text split into parts by the server
 * (`App\Support\PostNoteMentions::parts`), each mention carrying the person's
 * id and name, so the app never reads the stored markup. `noteDoc` turns the
 * parts into the note editor's document; the `NoteBody` TipTap plugin writes
 * the markup back when the note is saved.
 */
import type { JSONContent } from '@tiptap/core';

import { isEmpty } from '@/lib/helpers';
import type { PostNotePart } from '@/types/post-note';

/** The id a mention of everyone carries in the editor; it is never a user id. */
export const EVERYONE_ID = 'everyone';

/** Text as the editor holds it: a line break for each new line. */
const textNodes = (text: string): JSONContent[] =>
    text
        .split('\n')
        .flatMap((line, index): JSONContent[] => [
            ...(index > 0 ? [{ type: 'hardBreak' }] : []),
            ...(isEmpty(line) ? [] : [{ type: 'text', text: line }]),
        ]);

/**
 * A note's parts as the editor's document: one paragraph with a mention node
 * for each mention, labelled with the person's name (`labels.unknown` when
 * they are gone) or `labels.everyone`.
 */
export const noteDoc = (
    parts: PostNotePart[],
    labels: { everyone: string; unknown: string },
): JSONContent => ({
    type: 'doc',
    content: [
        {
            type: 'paragraph',
            content: parts.flatMap((part): JSONContent[] => {
                if (part.type === 'text') {
                    return textNodes(part.text);
                }

                return [
                    {
                        type: 'mention',
                        attrs:
                            part.type === 'everyone'
                                ? { id: EVERYONE_ID, label: labels.everyone }
                                : { id: part.id, label: part.name ?? labels.unknown },
                    },
                ];
            }),
        },
    ],
});
