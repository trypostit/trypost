import { uuid } from '@/lib/uuid';
import type { MediaItem } from '@/types/media';
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

/** Replies stored as plain strings are text-only replies. */
export const threadRepliesOf = (
    meta: Record<string, any> | null | undefined,
): ThreadReply[] =>
    Array.isArray(meta?.thread_replies)
        ? meta.thread_replies.map(
              (reply: unknown, index: number): ThreadReply => {
                  const stored =
                      reply !== null && typeof reply === 'object'
                          ? (reply as Partial<ThreadReply>)
                          : {};

                  return {
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
                      media: Array.isArray(stored.media) ? stored.media : [],
                      ...(stored.poll !== null && typeof stored.poll === 'object'
                          ? { poll: stored.poll }
                          : {}),
                  };
              },
          )
        : [];

/** The reply with `poll`, or as a plain post of the thread when `poll` is undefined. */
export const withReplyPoll = (
    reply: ThreadReply,
    poll: Partial<XPoll> | undefined,
): ThreadReply => {
    const next: ThreadReply = { ...reply, poll };

    if (poll === undefined) {
        delete next.poll;
    }

    return next;
};
