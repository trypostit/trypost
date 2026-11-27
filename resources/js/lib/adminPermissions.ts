import { humanize } from '@/lib/adminFormat';
import { filled } from '@/lib/helpers';

/** How the backoffice names each `GatedFeature` a channel's scopes turn on. */
const FEATURE_LABELS: Record<string, string> = {
    publish: 'Publish',
    thread_replies: 'Replies',
    followers: 'Followers',
    post_insights: 'Insights',
    post_insights_extended: 'Extended insights',
    post_history: 'Post history',
    private_post_read: 'Private reads',
    facebook_reel_cover: 'Reel cover',
};

/** A feature added later reads as its own name, without underscores. */
export const featureLabel = (feature: string): string => {
    const label = FEATURE_LABELS[feature];

    if (filled(label)) {
        return label;
    }

    return humanize(feature);
};
