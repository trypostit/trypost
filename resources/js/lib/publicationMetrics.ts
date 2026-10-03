import { trans } from 'laravel-vue-i18n';

import { formatNumberCompact, formatPercent } from '@/lib/utils';
import type { PublicationMetricFact } from '@/types/analytics';

/** Networks whose native name for a re-share is "repost"; stored as `shares`. */
const REPOST_NETWORKS = new Set(['x', 'threads', 'mastodon', 'bluesky']);

export const publicationMetricLabel = (
    metricKey: string,
    platform?: string | null,
): string => {
    const key =
        metricKey === 'shares' && REPOST_NETWORKS.has(platform ?? '')
            ? 'reposts'
            : metricKey;
    const core = `analytics.metrics.${key}`;
    const coreLabel = trans(core);
    if (coreLabel !== core) return coreLabel;

    return trans(`analytics.detail.labels.${key}`);
};

export const formatPublicationMetric = (
    key: string,
    fact: PublicationMetricFact,
): string => {
    if (fact.value === null) return '—';
    if (fact.unit === 'percent') return formatPercent(fact.value);
    if (fact.unit === 'milliseconds') {
        const average = key.includes('average');
        return `${formatNumberCompact(fact.value / (average ? 1000 : 60000))} ${average ? 's' : 'min'}`;
    }
    return formatNumberCompact(fact.value);
};

export const isVisiblePublicationMetric = (
    fact: PublicationMetricFact | undefined,
): fact is PublicationMetricFact =>
    fact?.availability === 'available' && fact.value !== null;

const STORY_METRICS = [
    'views',
    'reach',
    'replies',
    'engagement_rate',
    'reactions',
] as const;

const FEED_METRICS = [
    'reactions',
    'comments',
    'engagement_rate',
    'views',
    'shares',
    'saves',
    'follows',
    'reach',
] as const;

const VIDEO_METRICS = [
    'reactions',
    'comments',
    'engagement_rate',
    'views',
    'shares',
    'saves',
    'watch_time_milliseconds',
    'average_watch_time_milliseconds',
    'reach',
] as const;

const CONTENT_TYPE_METRICS: Record<string, readonly string[]> = {
    story: STORY_METRICS,
    video: VIDEO_METRICS,
    reel: VIDEO_METRICS,
    short: VIDEO_METRICS,
};

/**
 * A network's own ordered set, replacing the content-type set. `feedOnly`
 * keeps the content-type set for stories, reels and videos.
 */
const NETWORK_METRICS: Record<
    string,
    { metrics: readonly string[]; feedOnly?: boolean }
> = {
    facebook: {
        metrics: [
            'reactions',
            'comments',
            'engagement_rate',
            'impressions',
            'shares',
            'clicks',
        ],
        feedOnly: true,
    },
    x: {
        metrics: [
            'reactions',
            'comments',
            'engagement_rate',
            'impressions',
            'shares',
            'quotes',
            'bookmarks',
            'link_clicks',
        ],
    },
    threads: {
        metrics: [
            'reactions',
            'comments',
            'engagement_rate',
            'views',
            'quotes',
            'shares',
        ],
    },
    mastodon: { metrics: ['reactions', 'comments', 'shares'] },
    bluesky: { metrics: ['reactions', 'comments', 'shares', 'quotes'] },
    pinterest: {
        metrics: [
            'saves',
            'comments',
            'engagement_rate',
            'impressions',
            'reactions',
            'video_views',
            'pin_clicks',
            'outbound_clicks',
        ],
    },
    tiktok: {
        metrics: [
            'reactions',
            'comments',
            'engagement_rate',
            'views',
            'shares',
            'reach',
            'watch_time_milliseconds',
            'average_watch_time_milliseconds',
        ],
    },
    youtube: {
        metrics: [
            'reactions',
            'comments',
            'engagement_rate',
            'views',
            'shares',
            'saves',
            'watch_time_milliseconds',
            'average_watch_time_milliseconds',
            'engaged_views',
            'average_percentage_viewed',
            'subscribers_gained',
        ],
    },
};

/** The metric keys a post shows: its network's own set, else its content type's. */
export const publicationMetricKeys = (
    contentType: string | null | undefined,
    platform: string | null | undefined,
): readonly string[] => {
    const network = NETWORK_METRICS[platform ?? ''];
    const typed = CONTENT_TYPE_METRICS[contentType ?? ''];

    if (network && !(network.feedOnly && typed)) {
        return network.metrics;
    }

    return typed ?? FEED_METRICS;
};

/** The headline metrics a post card or post details band shows, in TryPost order. */
export const compactPublicationMetrics = (detail: {
    publication: { content_type: string; platform: string } | null;
    metrics: Record<string, PublicationMetricFact | undefined>;
}): { key: string; fact: PublicationMetricFact }[] =>
    publicationMetricKeys(
        detail.publication?.content_type,
        detail.publication?.platform,
    ).flatMap((key) => {
        const fact = detail.metrics[key];

        return isVisiblePublicationMetric(fact) ? [{ key, fact }] : [];
    });
