/**
 * A note in the editor: mention chips that read back as the stored markup
 * (`@[user:{id}]`, `@[everyone]`), so `noteBodyOf(editor)` is the body to
 * store. `setNoteParts(parts)` loads a note from the parts the server sends
 * (`lib/noteMentions.ts`). A copied chip reads `@Name`.
 */
import { Extension, type Editor } from '@tiptap/core';
import Mention from '@tiptap/extension-mention';
import type { Node as ProseMirrorNode } from '@tiptap/pm/model';
import { Plugin } from '@tiptap/pm/state';
import type { SuggestionOptions } from '@tiptap/suggestion';

import { EVERYONE_ID, noteDoc } from '@/lib/noteMentions';
import type { PostNotePart } from '@/types/post-note';

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
