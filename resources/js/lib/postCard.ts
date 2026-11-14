import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { transform } from '@/lib/helpers';
import { isImage, isVideo } from '@/lib/mediaType';
import type { MediaItem } from '@/types/media';
import type { PostCard } from '@/types/publish';

/** The channel a card names: the account's label, else the network's name; none without a channel. */
export const postChannelLabel = (post: PostCard): string =>
    transform(
        post.platform,
        (platform) =>
            post.social_account?.display_label ?? getPlatformLabel(platform),
        '',
    );

/** When the post went out, is due, or was created: the instant its list and board sort by (`Post::scopeLatestAttempt`). */
export const postAttemptAt = (post: PostCard): string =>
    post.published_at ?? post.scheduled_at ?? post.created_at;

/** The first image or video of the post, shown as the card's thumbnail. */
export const postThumbnail = (post: PostCard): MediaItem | null =>
    post.media?.find((item) => isImage(item) || isVideo(item)) ?? null;
