import type { SocialAccountStatusValue } from '@/types/social-account-status';

export interface Comparison {
    value: number | null;
    previous: number | null;
    change: number | null;
}

export interface AccountIdentityData {
    social_account_key: string;
    platform: string;
    name: string | null;
    username: string | null;
    avatar_url: string | null;
    status?: SocialAccountStatusValue | null;
}

export interface FollowerAccount extends AccountIdentityData {
    social_account_id: string | null;
    network: string;
    value: number | null;
    growth: number | null;
    provenance: string | null;
}

export interface PostAccount extends AccountIdentityData {
    count: number;
}

export interface PostBucket {
    start: string;
    end: string;
    accounts: Record<string, number>;
    total: number;
}

export interface TopPost {
    id: string;
    post_platform_id: string | null;
    post_id: string | null;
    social_account_key: string;
    platform: string;
    name: string | null;
    username: string | null;
    avatar_url: string | null;
    status?: SocialAccountStatusValue | null;
    origin: string;
    content_type: string;
    availability: string;
    published_at: string;
    permalink: string | null;
    excerpt: string | null;
    preview_metadata: Record<string, unknown> | null;
    reactions: number | null;
    comments: number | null;
}

export type PerformanceMetric =
    | 'posts'
    | 'reactions'
    | 'comments'
    | 'engagement_rate'
    | 'reposts'
    | 'impressions'
    | 'clicks'
    | 'views'
    | 'shares'
    | 'saves'
    | 'follows_gained'
    | 'reach'
    | 'watch_time_minutes'
    | 'average_watch_time_seconds';

export type PerformanceRow = AccountIdentityData &
    Record<PerformanceMetric, Comparison>;

export interface InsightsSyncCadence {
    discovery_hours: number;
    x_discovery_hours: number;
    metrics_days: number;
    x_metrics_days: number;
}

export interface CoverageRow {
    social_account_id: string;
    collector: string;
    status: string;
    target_since: string | null;
    oldest_reached_at: string | null;
    high_watermark_at: string | null;
    last_success_at: string | null;
    last_error_category: string | null;
}

export interface AnalyticsReport {
    bounds: { min: string | null; max: string | null };
    range: { start: string; end: string };
    previous_range: { start: string; end: string };
    summary: {
        posts: Comparison;
        followers: Comparison;
        reactions: Comparison;
        comments: Comparison;
        engagement_rate: Comparison;
        views: Comparison;
        reach: Comparison;
        shares: Comparison;
        saves: Comparison;
        watch_time_minutes: Comparison;
        average_watch_time_seconds: Comparison;
        follows_gained: Comparison;
    };
    followers: {
        total: number | null;
        accounts: FollowerAccount[];
        series: { date: string; accounts: Record<string, number | null> }[];
    };
    posts: {
        resolution: string;
        accounts: PostAccount[];
        buckets: PostBucket[];
    };
    top_posts: { reactions: TopPost[]; comments: TopPost[] };
    performance: PerformanceRow[];
    coverage: CoverageRow[];
}

export interface WorkspaceAnalyticsReport extends AnalyticsReport {
    filters: WorkspaceAnalyticsFilters;
}

export type AnalyticsRangePreset =
    | '7d'
    | '30d'
    | 'mtd'
    | 'last_month'
    | 'custom';

export interface AnalyticsFilters {
    range: AnalyticsRangePreset;
    start: string;
    end: string;
}

export interface WorkspaceAnalyticsFilters extends AnalyticsFilters {
    labels: string[];
    untagged: boolean;
    channels: string[];
}

export interface AnalyticsChannelOption {
    id: string;
    platform: string;
    display_label: string;
    username: string | null;
    avatar_url: string | null;
    status: SocialAccountStatusValue;
    analytics_key: string;
}

export type SummaryMetric = keyof AnalyticsReport['summary'];

export type PublicationPeriod = 'current' | 'previous';

export interface ChannelInsightsFilters extends AnalyticsFilters {
    period: PublicationPeriod;
    sort: SummaryMetric;
}

export interface ChannelPublicationRow {
    id: string;
    rank: number;
    excerpt: string | null;
    thumbnail_url: string | null;
    permalink: string | null;
    published_at: string;
    content_type: string | null;
    metrics: Partial<Record<SummaryMetric, number | null>>;
    url: string | null;
}

export interface PublicationMetricFact {
    value: number | null;
    unit: string;
    time_basis?: string;
    precision?: string;
    availability: string;
    provider_metric?: string | null;
}

export interface PublicationAnalyticsDetail {
    available: true;
    reason: null;
    publication: {
        id: string;
        post_platform_id: string | null;
        social_account_key: string;
        platform: string;
        origin: string;
        content_type: string;
        availability: string;
        provider_published_at: string | null;
        permalink: string | null;
        excerpt: string | null;
        preview_metadata: Record<string, unknown> | null;
        account_display_name: string | null;
        account_username: string | null;
        account_avatar_url: string | null;
    };
    snapshot: {
        date: string;
        collected_at: string | null;
        provider_observed_at: string | null;
    } | null;
    metrics: Record<string, PublicationMetricFact>;
}

export interface UnsupportedPublicationAnalytics {
    unsupported: true;
    reason: string;
}
