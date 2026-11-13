/**
 * A note in the editor. It opens from the parts the server sends
 * (`setNoteParts`, see `App\Support\PostNoteMentions::parts`) and reads back
 * as the body to store (`noteBodyOf`): each mention chip as its markup
 * (`@[user:{id}]`, `@[everyone]`) and each line break as a new line. A copied
 * chip reads `@Name`.
 */
import { Extension, type Editor, type JSONContent } from '@tiptap/core';
import Mention from '@tiptap/extension-mention';
import type { Node as ProseMirrorNode } from '@tiptap/pm/model';
import { Plugin } from '@tiptap/pm/state';
import type { SuggestionOptions } from '@tiptap/suggestion';

import { isEmpty } from '@/lib/helpers';
import type { PostNotePart } from '@/types/post-note';

/** The id a chip of everyone carries; it is never a user id. */
export const EVERYONE_ID = 'everyone';

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        noteBody: {
            /** Replaces the content with a note's parts, without an update event. */
            setNoteParts: (parts: PostNotePart[]) => ReturnType;
        };
    }
}

interface NoteBodyOptions {
    /** The word a chip of everyone shows, in the reader's language. */
    everyoneLabel: () => string;
    /** What a chip shows for someone no longer there. */
    unknownLabel: () => string;
    /** The `@` list shown while typing after `@`. */
    suggestion: Omit<SuggestionOptions, 'editor'>;
    /** Attributes of a chip (class, test id). */
    chipAttributes: Record<string, string>;
}

/** A copied chip as people read it, and a line break as a new line. */
const clipboardText = (leaf: ProseMirrorNode): string =>
    leaf.type.name === 'mention' ? `@${leaf.attrs.label}` : '\n';

/** Text as the editor holds it: a line break is its own node, not a `\n` in the text. */
const textNodes = (text: string): JSONContent[] =>
    text
        .split('\n')
        .flatMap((line, index): JSONContent[] => [
            ...(index > 0 ? [{ type: 'hardBreak' }] : []),
            ...(isEmpty(line) ? [] : [{ type: 'text', text: line }]),
        ]);

/** A note's parts as the editor's document: one paragraph of text, line breaks and chips. */
const noteDoc = (
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

/** A mention as the body stores it. */
const mentionMarkup = (id: string): string =>
    id === EVERYONE_ID ? '@[everyone]' : `@[user:${id}]`;

export const NoteBody = Extension.create<NoteBodyOptions>({
    name: 'noteBody',

    addOptions: () => ({
        everyoneLabel: () => '',
        unknownLabel: () => '',
        suggestion: {},
        chipAttributes: {},
    }),

    addExtensions() {
        return [
            Mention.configure({
                HTMLAttributes: this.options.chipAttributes,
                renderText: ({ node }) => mentionMarkup(node.attrs.id),
                suggestion: this.options.suggestion,
            }),
        ];
    },

    addProseMirrorPlugins() {
        return [
            new Plugin({
                props: {
                    clipboardTextSerializer: (slice) =>
                        slice.content.textBetween(
                            0,
                            slice.content.size,
                            '\n',
                            clipboardText,
                        ),
                },
            }),
        ];
    },

    addCommands() {
        return {
            setNoteParts:
                (parts) =>
                ({ commands }) =>
                    commands.setContent(
                        noteDoc(parts, {
                            everyone: this.options.everyoneLabel(),
                            unknown: this.options.unknownLabel(),
                        }),
                        { emitUpdate: false },
                    ),
        };
    },
});

/** The editor's content as the body to store. */
export const noteBodyOf = (editor: Editor): string =>
    editor.getText({ blockSeparator: '\n' });
