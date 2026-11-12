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
    reactions: PostNoteReaction[];
    user: PostNoteAuthor;
}

/** Someone a note mentions, as the composer knows them: a member, or everyone (`EVERYONE_ID`). */
export interface MentionedPerson {
    id: string;
    name: string;
}

/** The `@query` being typed in a note: where its `@` sits and what follows it. */
export interface PostNoteMentionQuery {
    start: number;
    query: string;
}

/** A member a note may mention, with what the mention card shows. */
export interface PostNoteMentionable {
    id: string;
    name: string;
    photo_url: string | null;
    timezone: string;
}
