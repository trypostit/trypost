import { blank } from '@/lib/helpers';
import {
    isBlankThreadReply,
    type ThreadReply,
    threadRepliesOf,
} from '@/lib/threadReplies';
import { postPollResults } from '@/lib/xPoll';
import type {
    PublicationPoll,
    PublicationThreadPoll,
} from '@/types/analytics';
import type { MediaItem } from '@/types/media';
import { THREAD_PLATFORMS } from '@/types/network-options';
import type { PostCard } from '@/types/publish';

/** One post of a thread as the post card and its details show it, with its own poll. */
export type ThreadPost = {
    text: string;
    media: MediaItem[];
    poll: PublicationPoll | null;
    /** Added to the poll's test id: none for the first post, `-reply-{n}` for a reply. */
    pollTestSuffix: string;
};

const replyPostOf = (
    reply: ThreadReply,
    index: number,
    polls: PublicationThreadPoll[],
): ThreadPost => ({
    text: reply.text,
    media: reply.media,
    poll: polls.find((poll) => poll.reply === index) ?? null,
    pollTestSuffix: `-reply-${index}`,
});

/**
 * Every post of a post's thread in publishing order, the first one included,
 * each with its text, media and poll, as the composer previews them. Empty
 * when the post has no reply to show.
 */
export const postThreadOf = (post: PostCard): ThreadPost[] => {
    if (!THREAD_PLATFORMS.includes(post.platform ?? '')) {
        return [];
    }

    const { poll, threadPolls } = postPollResults(post);
    const replies = threadRepliesOf(post.meta).flatMap((reply, index) => {
        if (isBlankThreadReply(reply)) {
            return [];
        }

        return [replyPostOf(reply, index, threadPolls)];
    });

    if (blank(replies)) {
        return [];
    }

    const first: ThreadPost = {
        text: post.content?.trim() ?? '',
        media: post.media ?? [],
        poll,
        pollTestSuffix: '',
    };

    return [first, ...replies];
};
