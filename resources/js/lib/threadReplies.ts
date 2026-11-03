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

const isObject = (value: unknown): value is Record<string, any> =>
    value !== null && typeof value === 'object';

/** Replies stored as plain strings are text-only replies. */
export const threadRepliesOf = (
    meta: Record<string, any> | null | undefined,
): ThreadReply[] =>
    Array.isArray(meta?.thread_replies)
        ? meta.thread_replies.map(
              (reply: unknown, index: number): ThreadReply => {
                  const stored: Partial<ThreadReply> = isObject(reply)
                      ? reply
                      : {};

                  return withReplyPoll(
                      {
                          key:
                              typeof stored.key === 'string'
                                  ? stored.key
                                  : `stored-${index}`,
                          text:
                              typeof reply === 'string'
                                  ? reply
                                  : typeof stored.text === 'string'
                                    ? stored.text
                                    : '',
                          media: Array.isArray(stored.media)
                              ? stored.media
                              : [],
                      },
                      isObject(stored.poll) ? stored.poll : undefined,
                  );
              },
          )
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
