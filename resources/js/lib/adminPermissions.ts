import type { GatedFeatureValue } from '@/types/channel-permission';

/** How the backoffice names each `GatedFeature` a channel's scopes turn on. */
const FEATURE_LABELS: Record<GatedFeatureValue, string> = {
    publish: 'Publish',
    thread_replies: 'Replies',
    followers: 'Followers',
    post_insights: 'Insights',
    post_insights_extended: 'Extended insights',
    post_history: 'Post history',
    private_post_read: 'Private reads',
    facebook_reel_cover: 'Reel cover',
};

export const featureLabel = (feature: GatedFeatureValue): string => FEATURE_LABELS[feature];
