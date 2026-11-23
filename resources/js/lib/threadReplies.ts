import { characterCount, displayText } from '@/lib/characters';
import { blank, filled, isEmpty, isString } from '@/lib/helpers';
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
}: ThreadReply): boolean => isEmpty(text) && blank(media) && blank(poll);

/** Stored JSON can contain legacy text or an object; `key` only exists in composer drafts. */
type StoredThreadReply = string | Partial<ThreadReply>;
type StoredThreadMeta = Record<string, unknown> & {
    thread_replies?: StoredThreadReply[] | null;
};

/** A stored reply as the composer edits it, keyed by its position when it has no key yet. */
const fromStored = (reply: StoredThreadReply, index: number): ThreadReply => {
    const storedKey = `stored-${index}`;

    if (isString(reply)) {
        return { key: storedKey, text: reply, media: [] };
    }

    const { key = storedKey, text = '', media = [], poll } = reply;

    return withReplyPoll({ key, text, media }, poll);
};

/** The replies of a post's thread, read from its meta. */
export const threadRepliesOf = (
    meta: StoredThreadMeta | null | undefined,
): ThreadReply[] => (meta?.thread_replies ?? []).map(fromStored);

const COMMENT_LINE_BREAK_SELECTOR =
    'br, p, li, div, h1, h2, h3, h4, h5, h6, blockquote';
const HTML_REFERENCES = /&(?:[a-z][a-z0-9]*|#(?:x[0-9a-f]+|[0-9]+));/gi;
const UNICODE_MAX = 0x10ffff;
const UNICODE_PLANE_SIZE = 0x10000;
const FIRST_NONCHARACTER_IN_PLANE = 0xfffe;

/** Numeric references that PHP's HTML5 decoder leaves as written. */
const INVALID_HTML_REFERENCE_RANGES: ReadonlyArray<readonly [number, number]> =
    [
        [0x00, 0x08],
        [0x0b, 0x0b],
        [0x0d, 0x1f],
        [0x7f, 0x9f],
        [0xd800, 0xdfff],
        [0xfdd0, 0xfdef],
    ];

const decodeNumericReference = (entity: string): string => {
    const digits = entity.slice(2, -1).toLowerCase();
    const codePoint = Number(digits.replace('x', '0x'));

    if (codePoint > UNICODE_MAX) {
        return entity;
    }

    if (codePoint % UNICODE_PLANE_SIZE >= FIRST_NONCHARACTER_IN_PLANE) {
        return entity;
    }

    if (
        INVALID_HTML_REFERENCE_RANGES.some(
            ([start, end]) => codePoint >= start && codePoint <= end,
        )
    ) {
        return entity;
    }

    return String.fromCodePoint(codePoint);
};

const commentWithoutMarkup = (text: string): string => {
    if (!text.includes('<')) {
        return text;
    }

    const template = document.createElement('template');
    // Keep references and literal angle brackets intact while stripping legacy markup.
    template.innerHTML = text
        .replaceAll('&', '&amp;')
        .replace(/<(?!\/?[a-z][^<>]*>)/gi, '&lt;');

    for (const item of template.content.querySelectorAll('li')) {
        item.prepend('- ');
    }

    for (const block of template.content.querySelectorAll(
        COMMENT_LINE_BREAK_SELECTOR,
    )) {
        if (block.tagName === 'BR' && block.hasAttributes()) {
            continue;
        }

        block.after('\n');
    }

    return template.content.textContent ?? '';
};

/** Displays the plain text used by Laravel's ContentSanitizer for Instagram comments. */
export const instagramCommentText = (text: string): string => {
    let decoder: HTMLTextAreaElement | undefined;
    const decoded = commentWithoutMarkup(text).replace(
        HTML_REFERENCES,
        (entity) => {
            if (entity.startsWith('&#')) {
                return decodeNumericReference(entity);
            }

            decoder ??= document.createElement('textarea');
            decoder.innerHTML = entity;

            // A valid named reference decodes fully to one or two characters.
            if (characterCount(decoder.value) <= 2) {
                return decoder.value;
            }

            return entity;
        },
    );

    return displayText(decoded);
};

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

const COMMENT_CONTENT_TYPES: readonly ContentTypeValue[] = [
    ContentType.InstagramComment,
    ContentType.LinkedInComment,
];

export const repliesAreComments = (platform: PlatformValue): boolean => {
    const type = threadReplyPostType(platform);

    if (!filled(type)) {
        return false;
    }

    return COMMENT_CONTENT_TYPES.includes(type);
};

export const replyTooLongKey = (platform: PlatformValue): string => {
    if (repliesAreComments(platform)) {
        return 'posts.form.thread.comment_too_long';
    }

    return 'posts.form.thread.reply_too_long';
};

export const startThreadKey = (platform: PlatformValue): string => {
    const type = threadReplyPostType(platform);

    if (type === ContentType.InstagramComment) {
        return 'posts.form.thread.first_comment';
    }

    if (type === ContentType.LinkedInComment) {
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

/**
 * Why the network cannot accept replies for this post type. `repliesEnabled`
 * is whether the network asks for the permissions its replies need.
 */
export const threadUnsupportedKey = (
    platform: PlatformValue,
    contentType: ContentTypeValue | null,
    repliesEnabled: boolean,
): PostFormTranslationKey | null => {
    if (threadMaxReplies(platform) === 0) {
        return 'posts.form.thread.unsupported';
    }

    if (!repliesEnabled) {
        return 'posts.form.thread.unsupported';
    }

    if (!filled(contentType)) {
        return null;
    }

    return STANDALONE_CONTENT_TYPES[contentType] ?? null;
};

/** A post with stored replies reports the same availability error as `ThreadReplies::violation()`. */
export const threadUnavailableKey = (
    platform: PlatformValue,
    contentType: ContentTypeValue | null,
    meta: StoredThreadMeta | null,
    repliesEnabled: boolean,
): PostFormTranslationKey | null => {
    if (blank(threadRepliesOf(meta))) {
        return null;
    }

    return threadUnsupportedKey(platform, contentType, repliesEnabled);
};
