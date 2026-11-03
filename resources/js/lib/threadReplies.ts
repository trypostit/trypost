import { uuid } from '@/lib/uuid';
import { ContentType } from '@/types/content-type';
import type { MediaItem } from '@/types/media';
import { THREAD_REPLY_CONTENT_TYPES } from '@/types/network-options';
import type { XPoll } from '@/types/x';

/**
 * One post of a thread after the first. `key` only lives in the composer: it
 * keeps an upload attached to its reply while replies are added or removed
 * above it, and the server drops it. On X a reply may carry a poll instead of
 * media, its text being the question.
 */
export type ThreadReply = {
    key: string;
    text: string;
    media: MediaItem[];
    poll?: Partial<XPoll>;
};

export const newThreadReply = (): ThreadReply => ({
    key: uuid(),
    text: '',
    media: [],
});

/** A reply with nothing in it yet: no text, no media and no poll. */
export const isBlankThreadReply = ({
    text,
    media,
    poll,
}: ThreadReply): boolean =>
    text === '' && media.length === 0 && poll === undefined;

const isObject = (value: unknown): value is Record<string, any> =>
    value !== null && typeof value === 'object';

/** One stored reply as the composer edits it. */
const storedReply = (stored: unknown, index: number): ThreadReply => {
    const reply: Record<string, any> = isObject(stored) ? stored : {};

    return withReplyPoll(
        {
            key: typeof reply.key === 'string' ? reply.key : `stored-${index}`,
            text: typeof reply.text === 'string' ? reply.text : '',
            media: Array.isArray(reply.media) ? reply.media : [],
        },
        isObject(reply.poll) ? reply.poll : undefined,
    );
};

/** The replies of a post's thread, read from its meta. */
export const threadRepliesOf = (
    meta: Record<string, any> | null | undefined,
): ThreadReply[] =>
    Array.isArray(meta?.thread_replies)
        ? meta.thread_replies.map(storedReply)
        : [];

/** The reply with `poll`, or as a plain post of the thread when `poll` is undefined. */
export const withReplyPoll = (
    { key, text, media }: ThreadReply,
    poll: Partial<XPoll> | undefined,
): ThreadReply =>
    poll === undefined ? { key, text, media } : { key, text, media, poll };

/** The post type a reply publishes as: a poll, or its network's reply type. */
export const threadReplyContentType = (
    platform: string,
    reply: ThreadReply,
): string | undefined =>
    reply.poll ? ContentType.XPoll : THREAD_REPLY_CONTENT_TYPES[platform];
