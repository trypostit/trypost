export interface PostNoteAuthor {
    id: string;
    name: string;
    photo_url?: string | null;
}

export interface PostNote {
    id: string;
    body: string;
    user_id: string;
    created_at: string;
    updated_at: string;
    user: PostNoteAuthor;
}
