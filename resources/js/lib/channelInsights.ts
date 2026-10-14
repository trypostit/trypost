import { Platform } from '@/types/platform';

/** Whether the channel insights offer the post type filter. */
export const showsPostTypeFilter = (platform: string): boolean => {
    switch (platform) {
        case Platform.Instagram:
        case Platform.InstagramFacebook:
        case Platform.Facebook:
        case Platform.TikTok:
        case Platform.Pinterest:
            return true;
        case Platform.Threads:
        case Platform.X:
        case Platform.YouTube:
        case Platform.Bluesky:
        case Platform.Mastodon:
        default:
            return false;
    }
};

/** Whether the per post table tags each post with its type. */
export const showsPostTypeTag = (platform: string): boolean => {
    switch (platform) {
        case Platform.YouTube:
        case Platform.TikTok:
        case Platform.Bluesky:
        case Platform.Mastodon:
            return false;
        default:
            return true;
    }
};
