/**
 * What every plain-text TipTap editor of the app is made of: one paragraph of
 * text with line breaks (Shift+Enter). Editors add their behaviour from
 * `components/tiptap/plugins`.
 */
import type { AnyExtension } from '@tiptap/core';
import Document from '@tiptap/extension-document';
import HardBreak from '@tiptap/extension-hard-break';
import Paragraph from '@tiptap/extension-paragraph';
import Text from '@tiptap/extension-text';
import { Placeholder } from '@tiptap/extensions';

/** The plain-text nodes, with a placeholder read each time the editor is empty. */
export const plainTextExtensions = (
    placeholder: () => string,
): AnyExtension[] => [
    Document,
    Paragraph,
    Text,
    HardBreak,
    Placeholder.configure({ placeholder }),
];
