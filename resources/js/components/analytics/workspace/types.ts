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
    label: string;
    accounts: Record<string, number>;
    total: number;
}

export interface TopPost {
    id: string;
    post_platform_id: string | null;
    social_account_key: string;
    platform: string;
    name: string | null;
    username: string | null;
    origin: string;
    content_type: string;
    published_at: string;
    permalink: string | null;
    excerpt: string | null;
    preview_metadata: Record<string, unknown> | null;
    reactions: number | null;
    comments: number | null;
}

export interface PerformanceRow extends AccountIdentityData {
    posts: Comparison;
    reactions: Comparison;
    comments: Comparison;
    engagement_rate: Comparison;
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

export interface WorkspaceAnalyticsReport {
    bounds: { min: string | null; max: string | null };
    range: { start: string; end: string };
    previous_range: { start: string; end: string };
    summary: {
        posts: Comparison;
        followers: Comparison;
        reactions: Comparison;
        comments: Comparison;
        engagement_rate: Comparison;
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

export const accountColors = [
    '#6D43CC',
    '#159B92',
    '#E9833D',
    '#347AB7',
    '#C64F7B',
    '#74894B',
];

export const accountColor = (index: number): string =>
    accountColors[index % accountColors.length];
