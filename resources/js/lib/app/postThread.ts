import { postPollResults } from '@/lib/app/poll';
import {
    isBlankThreadReply,
    threadRepliesOf,
} from '@/lib/app/threadReplies';
import { blank, filled } from '@/lib/helpers';
import type { PublicationThreadPoll } from '@/types/analytics';
import { isThreadPlatform } from '@/types/app/network-options';
import type { PostCard } from '@/types/app/publish';
import type { ThreadPost, ThreadReply } from '@/types/app/thread-reply';

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
    if (!filled(post.platform) || !isThreadPlatform(post.platform)) {
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
