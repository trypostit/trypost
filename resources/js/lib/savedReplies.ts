export interface SavedReply {
    id: string;
    name: string;
    content: string;
}

export interface SavedReplyPerson {
    name: string | null;
    username: string | null;
}

export const SAVED_REPLY_NAME_MAX_LENGTH = 100;
export const SAVED_REPLY_CONTENT_MAX_LENGTH = 1000;

export const SAVED_REPLY_VARIABLES = ['name', 'first_name', 'username'] as const;

export type SavedReplyVariable = (typeof SAVED_REPLY_VARIABLES)[number];

const stripAt = (username: string | null): string =>
    (username ?? '').trim().replace(/^@+/, '');

/**
 * The value each variable takes for the person being answered: the full
 * name, its first word, and the handle without "@". A missing name falls
 * back to the handle; with neither the variable is left empty.
 */
export const savedReplyValues = (
    person: SavedReplyPerson,
): Record<SavedReplyVariable, string> => {
    const username = stripAt(person.username);
    const name = (person.name ?? '').trim();

    return {
        name: name || username,
        first_name: name.split(/\s+/)[0] || username,
        username,
    };
};

/** The saved reply's text with {name}, {first_name} and {username} filled in. */
export const fillSavedReply = (
    content: string,
    person: SavedReplyPerson,
): string => {
    const values = savedReplyValues(person);

    return content.replace(
        /\{(name|first_name|username)\}/g,
        (_match, variable: SavedReplyVariable) => values[variable],
    );
};

/**
 * `text` with `insertion` put at the textarea's selection (or at the end),
 * and where the caret goes after it.
 */
export const insertAtSelection = (
    text: string,
    insertion: string,
    element: HTMLTextAreaElement | HTMLInputElement | null,
): { text: string; caret: number } => {
    const start = element?.selectionStart ?? text.length;
    const end = element?.selectionEnd ?? text.length;

    return {
        text: `${text.slice(0, start)}${insertion}${text.slice(end)}`,
        caret: start + insertion.length,
    };
};
