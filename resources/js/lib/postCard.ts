import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { isImage, isVideo } from '@/lib/mediaType';
import type { MediaItem } from '@/types/media';
import type { PostCard } from '@/types/publish';

/** The channel a card names: the account's label, else the network's name; none without a channel. */
export const postChannelLabel = (post: PostCard): string =>
    post.platform
        ? (post.social_account?.display_label ??
          getPlatformLabel(post.platform))
        : '';

/** The first image or video of the post, shown as the card's thumbnail. */
export const postThumbnail = (post: PostCard): MediaItem | null =>
    (post.media ?? []).find((item) => isImage(item) || isVideo(item)) ?? null;
