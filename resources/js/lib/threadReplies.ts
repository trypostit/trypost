import { blank, filled, isEmpty } from '@/lib/helpers';
import { uuid } from '@/lib/uuid';
import {
    ContentType,
    type ContentTypeValue,
    type PostFormTranslationKey,
    STANDALONE_CONTENT_TYPES,
} from '@/types/content-type';
import {
    isReplyPollPlatform,
    isThreadPlatform,
    THREAD_MAX_REPLIES,
    THREAD_REPLY_CONTENT_TYPES,
    THREAD_REPLY_POLL_CONTENT_TYPES,
} from '@/types/network-options';
import type { PlatformValue } from '@/types/platform';
import type { Poll } from '@/types/poll';
import type { ThreadReply } from '@/types/thread-reply';

export const newThreadReply = (): ThreadReply => ({
    key: uuid(),
    text: '',
    media: [],
});

export const insertThreadReply = (
    replies: ThreadReply[],
    position: number,
): ThreadReply[] => replies.toSpliced(position, 0, newThreadReply());

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
    poll: Partial<Poll> | undefined,
): ThreadReply => {
    if (blank(poll)) {
        return { key, text, media };
    }

    return { key, text, media: [], poll };
};

/**
 * The error a reply shows under its text, from the page's validation errors
 * under `prefix` (`meta.thread_replies`, or `destinations.{n}.meta.thread_replies`
 * in a batch): the reply's own, its text's or its media's. Its poll's errors
 * show under the poll.
 */
export const replyErrorOf = (
    errors: Record<string, string>,
    prefix: string,
    index: number,
): string | undefined =>
    errors[`${prefix}.${index}`] ??
    errors[`${prefix}.${index}.text`] ??
    errors[`${prefix}.${index}.media`];

/** The post type a reply that is no poll publishes as, on a network that chains replies. */
export const threadReplyPostType = (
    platform: PlatformValue,
): ContentTypeValue | undefined => {
    if (!isThreadPlatform(platform)) {
        return undefined;
    }

    return THREAD_REPLY_CONTENT_TYPES[platform];
};

/** The post type a reply publishes as: a poll, or its network's reply type. */
export const threadReplyContentType = (
    platform: PlatformValue,
    reply: ThreadReply,
): ContentTypeValue | undefined => {
    if (!filled(reply.poll)) {
        return threadReplyPostType(platform);
    }

    if (!isReplyPollPlatform(platform)) {
        return undefined;
    }

    return THREAD_REPLY_POLL_CONTENT_TYPES[platform];
};

export const threadMaxReplies = (platform: PlatformValue): number => {
    if (!isThreadPlatform(platform)) {
        return 0;
    }

    return THREAD_MAX_REPLIES[platform];
};

export const repliesAreComments = (platform: PlatformValue): boolean =>
    threadReplyPostType(platform) === ContentType.LinkedInComment;

export const replyTooLongKey = (platform: PlatformValue): string => {
    if (repliesAreComments(platform)) {
        return 'posts.form.thread.comment_too_long';
    }

    return 'posts.form.thread.reply_too_long';
};

export const startThreadKey = (platform: PlatformValue): string => {
    if (repliesAreComments(platform)) {
        return 'posts.form.thread.add_comment';
    }

    return 'posts.form.thread.start';
};

export const replyEmptyKey = (platform: PlatformValue): string => {
    if (repliesAreComments(platform)) {
        return 'posts.form.thread.comment_empty';
    }

    return 'posts.form.thread.reply_empty';
};

/** Whether a post type takes thread replies on its network. */
export const takesThreadReplies = (contentType: ContentTypeValue | null): boolean => {
    if (!filled(contentType)) {
        return true;
    }

    return blank(STANDALONE_CONTENT_TYPES[contentType]);
};

/**
 * Why a post's thread cannot go out, as `ThreadReplies::violation()` says on
 * the server: the network's connect does not ask for the scope replies need
 * (`repliesEnabled` false), or the post type stands alone. Null when it can.
 */
export const threadUnavailableKey = (
    contentType: ContentTypeValue | null,
    meta: Record<string, any> | null,
    repliesEnabled: boolean,
): PostFormTranslationKey | null => {
    if (blank(threadRepliesOf(meta))) {
        return null;
    }

    if (!repliesEnabled) {
        return 'posts.form.thread.unsupported';
    }

    if (!filled(contentType)) {
        return null;
    }

    return STANDALONE_CONTENT_TYPES[contentType] ?? null;
};
