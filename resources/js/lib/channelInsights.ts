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
            return false;
        default:
            return true;
    }
};

/** The post table columns the user picked for a network, read from this browser. */
export const storedChannelColumns = (platform: string): string[] | null => {
    try {
        const stored: unknown = JSON.parse(
            window.localStorage.getItem(`insights.columns.${platform}`) ??
                'null',
        );

        return Array.isArray(stored) ? (stored as string[]) : null;
    } catch {
        return null;
    }
};
