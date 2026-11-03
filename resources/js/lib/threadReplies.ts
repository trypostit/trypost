import { blank } from '@/lib/helpers';
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
    text === '' && blank(media) && poll === undefined;

/** A reply as the server stores it; `key` only exists in a draft the composer saved. */
type StoredThreadReply = Omit<ThreadReply, 'key'> & { key?: string };

/** The replies of a post's thread, read from its meta. */
export const threadRepliesOf = (
    meta: Record<string, any> | null | undefined,
): ThreadReply[] =>
    (meta?.thread_replies ?? []).map(
        ({ key, text, media, poll }: StoredThreadReply, index: number) =>
            withReplyPoll({ key: key ?? `stored-${index}`, text, media }, poll),
    );

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
