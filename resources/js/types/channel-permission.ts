import type { ContentTypeValue } from '@/types/content-type';
import type { MediaItem } from '@/types/media';

/**
 * What TryPost does on a channel that needs the account to have granted a
 * scope (`App\Enums\SocialAccount\GatedFeature`).
 */
export const GatedFeature = {
    Publish: 'publish',
    ThreadReplies: 'thread_replies',
    Followers: 'followers',
    PostInsights: 'post_insights',
    PostInsightsExtended: 'post_insights_extended',
    PostHistory: 'post_history',
    PrivatePostRead: 'private_post_read',
    FacebookReelCover: 'facebook_reel_cover',
} as const;

export type GatedFeatureValue = (typeof GatedFeature)[keyof typeof GatedFeature];

/** The copy a channel lacking a feature's scope is shown: `message`, and `member_message` for someone who cannot reconnect it. */
export type MissingPermission = { message: string; member_message: string };

/** What a post uses that may need a scope: its meta (thread replies), its type and its media (a Reel cover). */
export type PostFeatureUse = {
    meta: Record<string, unknown> | null;
    contentType: ContentTypeValue | null;
    media: MediaItem[];
};

/** The features a channel lacks a scope for (`ChannelPermissions::missing()`). */
export type MissingPermissions = Partial<Record<GatedFeatureValue, MissingPermission>>;
