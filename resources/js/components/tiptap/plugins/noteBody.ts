/**
 * A note body in the editor: mention chips that read back as the stored
 * markup (`@[user:{id}]`, `@[everyone]`, see `lib/noteMentions.ts`), so
 * `noteBodyOf(editor)` is the body to store and `setNoteBody(body)` loads a
 * stored one, each chip labelled by `label`. What the editor shows is what it
 * stores: pasted markup becomes a chip, and a copied chip reads `@Name`.
 */
import { Extension, nodePasteRule, type Editor } from '@tiptap/core';
import Mention from '@tiptap/extension-mention';
import type { Node as ProseMirrorNode } from '@tiptap/pm/model';
import { Plugin } from '@tiptap/pm/state';
import type { SuggestionOptions } from '@tiptap/suggestion';

import {
    EVERYONE_ID,
    MENTION_PATTERN,
    noteBodyToDoc,
} from '@/lib/noteMentions';

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        noteBody: {
            /** Replaces the content with a stored body, without an update event. */
            setNoteBody: (body: string) => ReturnType;
        };
    }
}

interface NoteBodyOptions {
    /** The name a chip shows for an id (`EVERYONE_ID` for everyone). */
    label: (id: string) => string;
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
        label: (id: string) => id,
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

    addPasteRules() {
        return [
            nodePasteRule({
                find: MENTION_PATTERN,
                type: this.editor.schema.nodes.mention,
                getAttributes: (match) => {
                    const id = match[1]?.toLowerCase() ?? EVERYONE_ID;

                    return { id, label: this.options.label(id) };
                },
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
            setNoteBody:
                (body) =>
                ({ commands }) =>
                    commands.setContent(noteBodyToDoc(body, this.options.label), {
                        emitUpdate: false,
                    }),
        };
    },
});

/** The editor's content as the body to store. */
export const noteBodyOf = (editor: Editor): string =>
    editor.getText({ blockSeparator: '\n' });
