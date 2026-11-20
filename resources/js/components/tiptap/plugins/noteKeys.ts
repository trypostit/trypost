/**
 * Enter sends and Esc cancels, like a chat input; Shift+Enter still breaks
 * the line. Each key is left to the editor while `submitBlocked` /
 * `cancelBlocked` say so (an open `@` list picks with Enter and closes on Esc).
 */
import { Extension } from '@tiptap/core';

interface NoteKeysOptions {
    onSubmit: () => void;
    onCancel: () => void;
    submitBlocked: () => boolean;
    cancelBlocked: () => boolean;
}

export const NoteKeys = Extension.create<NoteKeysOptions>({
    name: 'noteKeys',

    addOptions: () => ({
        onSubmit: () => {},
        onCancel: () => {},
        submitBlocked: () => false,
        cancelBlocked: () => false,
    }),

    addKeyboardShortcuts() {
        return {
            Enter: () => {
                if (this.options.submitBlocked()) {
                    return false;
                }

                this.options.onSubmit();

                return true;
            },
            Escape: () => {
                if (this.options.cancelBlocked()) {
                    return false;
                }

                this.options.onCancel();

                return true;
            },
        };
    },
});
