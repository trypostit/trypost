import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { filled } from '@/lib/helpers';
import { isImage, isVideo } from '@/lib/mediaType';
import type { MediaItem } from '@/types/media';
import { PostStatus } from '@/types/post';
import type { PostCard } from '@/types/publish';

/** The channel a card names: the account's label, else the network's name; none without a channel. */
export const postChannelLabel = (post: PostCard): string => {
    if (!filled(post.platform)) {
        return '';
    }

    if (filled(post.social_account?.display_label)) {
        return post.social_account.display_label;
    }

    return getPlatformLabel(post.platform);
};

/** When the post went out or is due; none for a draft or approval request without a time. */
export const postShownAt = (post: PostCard): string | null => {
    if (filled(post.published_at)) {
        return post.published_at;
    }

    if (filled(post.scheduled_at)) {
        return post.scheduled_at;
    }

    return null;
};

/** When the post went out, is due, or was created: the instant its list and board sort by (`Post::scopeLatestAttempt`). */
export const postAttemptAt = (post: PostCard): string => {
    const shownAt = postShownAt(post);

    if (filled(shownAt)) {
        return shownAt;
    }

    return post.created_at;
};

/** What a post without a time shows: the queue slot its approval request asks for, else "No time". */
export const postNoTimeKey = (post: PostCard): string => {
    const slot = post.approval_queue_position;

    if (post.status === PostStatus.PendingApproval && filled(slot)) {
        return `posts.publish.pending_slot.${slot}`;
    }

    return 'posts.publish.no_time';
};

/** The first image or video of the post, shown as the card's thumbnail. */
export const postThumbnail = (post: PostCard): MediaItem | null => {
    const thumbnail = post.media?.find((item) => isImage(item) || isVideo(item));

    if (!filled(thumbnail)) {
        return null;
    }

    return thumbnail;
};
