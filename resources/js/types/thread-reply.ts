import type { PublicationPoll } from '@/types/analytics';
import type { MediaItem } from '@/types/media';
import type { Poll } from '@/types/poll';

/**
 * One post of a thread after the first. `key` only lives in the composer: it
 * keeps an upload attached to its reply while replies are added or removed
 * above it, and the server drops it. On X and Mastodon a reply may carry a
 * poll instead of media, its text being the question.
 */
export type ThreadReply = {
    key: string;
    text: string;
    media: MediaItem[];
    poll?: Partial<Poll>;
};

/** One post of a thread as the post card and its details show it, with its own poll. */
export type ThreadPost = {
    text: string;
    media: MediaItem[];
    poll: PublicationPoll | null;
    /** Added to the poll's test id: none for the first post, `-reply-{n}` for a reply. */
    pollTestSuffix: string;
};
