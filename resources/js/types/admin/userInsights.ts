import type { AdminBarRow } from '@/types/admin/shared';

/** A sign that a paying account may leave, as `BuildChurnRisk` reads it. */
export type ChurnSignal =
    | 'dormant'
    | 'at_risk'
    | 'never_posted'
    | 'no_channels'
    | 'cancelling'
    | 'past_due'
    | 'channels_broken'
    | 'posting_dropped'
    | 'failing';

/** How likely a paying account is to leave, as `BuildChurnRisk` grades it. */
export type RiskLevel = 'high' | 'medium' | 'low';

export interface AdminAccountRow {
    account_id: string;
    user_id: string | null;
    name: string | null;
    email: string | null;
    plan: string | null;
}

export interface AdminUsageTotals {
    active_accounts: number;
    posts: number;
    posts_per_active: number;
    channels_per_account: number;
}

export interface AdminUsageInsights {
    totals: AdminUsageTotals;
    previous: Omit<AdminUsageTotals, 'channels_per_account'>;
    lifecycle: { customers: number; stages: AdminBarRow[] };
    top_accounts: (AdminAccountRow & { posts: number; automated: number; channels: number; failed: number })[];
    channel_buckets: AdminBarRow[];
    post_buckets: AdminBarRow[];
}

export interface AdminChurnRisk {
    base: number;
    active: number;
    at_risk: number;
    at_risk_share: number;
    mrr_at_risk_cents: number;
    levels: Partial<Record<RiskLevel, number>>;
    /** Most frequent first. */
    signals: Partial<Record<ChurnSignal, number>>;
    accounts: (AdminAccountRow & {
        mrr_cents: number;
        posts: number;
        previous_posts: number;
        stage: string;
        last_posted_at: string | null;
        signals: ChurnSignal[];
        level: RiskLevel;
    })[];
}
