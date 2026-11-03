import { blank, filled, isEmpty } from '@/lib/helpers';
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
    isEmpty(text) && blank(media) && blank(poll);

/** A reply as the server stores it; `key` only exists in a draft the composer saved. */
type StoredThreadReply = Omit<ThreadReply, 'key'> & { key?: string };

/** A stored reply as the composer edits it, keyed by its position when it has no key yet. */
const fromStored = (
    { key, text, media, poll }: StoredThreadReply,
    index: number,
): ThreadReply =>
    withReplyPoll({ key: key ?? `stored-${index}`, text, media }, poll);

/** The replies of a post's thread, read from its meta. */
export const threadRepliesOf = (
    meta: Record<string, any> | null | undefined,
): ThreadReply[] => (meta?.thread_replies ?? []).map(fromStored);

/** The reply as a poll, which carries no media, or as a plain post without one. */
export const withReplyPoll = (
    { key, text, media }: ThreadReply,
    poll: Partial<XPoll> | undefined,
): ThreadReply => {
    if (blank(poll)) {
        return { key, text, media };
    }

    return { key, text, media: [], poll };
};

/**
 * The error each reply of a thread shows under its text, from the page's
 * validation errors under `prefix` (`meta.thread_replies`, or
 * `destinations.{n}.meta.thread_replies` in a batch): the first error of the
 * reply or of a field in it, except its poll's, which show under the poll.
 */
export const replyErrorsOf = (
    errors: Record<string, string>,
    prefix: string,
): Record<number, string> => {
    const replyKey = new RegExp(
        `^${prefix.replaceAll('.', '\\.')}\\.(\\d+)(?:\\.(\\w+))?`,
    );
    const found: Record<number, string> = {};

    for (const [key, message] of Object.entries(errors)) {
        const [, index, field] = key.match(replyKey) ?? [];
        const showsUnderReply = filled(index) && field !== 'poll';

        if (showsUnderReply) {
            found[Number(index)] ??= message;
        }
    }

    return found;
};

/** The post type a reply publishes as: a poll, or its network's reply type. */
export const threadReplyContentType = (
    platform: string,
    reply: ThreadReply,
): string | undefined => {
    if (filled(reply.poll)) {
        return ContentType.XPoll;
    }

    return THREAD_REPLY_CONTENT_TYPES[platform];
};
