export interface PostNoteAuthor {
    id: string;
    name: string;
    photo_url?: string | null;
}

export interface PostNoteReaction {
    emoji: string;
    count: number;
    users: { id: string; name: string }[];
}

export interface PostNote {
    id: string;
    body: string;
    user_id: string;
    created_at: string;
    updated_at: string;
    mentions: MentionedPerson[];
    parts: PostNotePart[];
    reactions: PostNoteReaction[];
    user: PostNoteAuthor;
}

/** A page of a post's notes, newest first, as the publish page sends it (`postNotes`). */
export interface PostNotesPage {
    data: PostNote[];
    meta: {
        total: number;
    };
}

/** A piece of a note's text, as the server splits it: text, a person (null name when they are gone) or everyone. */
export type PostNotePart =
    | { type: 'text'; text: string }
    | { type: 'mention'; id: string; name: string | null }
    | { type: 'everyone' };

/** A member a note mentions, with their current name; the body holds only the id. */
export interface MentionedPerson {
    id: string;
    name: string;
}

/** A member a note may mention, with what the mention card shows. */
export interface PostNoteMentionable {
    id: string;
    name: string;
    photo_url: string | null;
    timezone: string;
}
