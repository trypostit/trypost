import { ContentType } from '@/types/content-type';

/** Content types that carry no media, with why a card holding media cannot switch to one. */
export const MEDIALESS_CONTENT_TYPE_REASONS: Readonly<Record<string, string>> = {
    [ContentType.ThreadsGhostPost]: 'posts.form.threads.ghost_post_attachments',
    [ContentType.XPoll]: 'posts.form.x.poll.attachments',
};

/** Content types that carry no media: the composer hides the media tray. */
export const MEDIALESS_CONTENT_TYPES: ReadonlySet<string> = new Set(
    Object.keys(MEDIALESS_CONTENT_TYPE_REASONS),
);
