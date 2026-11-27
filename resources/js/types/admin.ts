import type { BadgeVariants } from '@/components/ui/badge';
import type { MediaType } from '@/lib/mediaType';
import type { ChangelogEntry } from '@/types/changelog';
import type { GatedFeatureValue } from '@/types/channel-permission';
import type { SocialAccountStatusValue } from '@/types/social-account-status';

/** A `Badge` variant, as the backoffice maps a state to one. */
export type BadgeVariant = NonNullable<BadgeVariants['variant']>;

/** The Users list's tabs (`App\Enums\Admin\UserStatusFilter`). */
export type UserStatusFilter = 'all' | 'blocked';

/** The Subscriptions list's tabs (`App\Enums\Admin\SubscriptionStatusFilter`). */
export type SubscriptionStatusFilter = 'all' | 'active' | 'cancelling' | 'past_due' | 'trialing' | 'canceled';

/** A network whose quota the backoffice reads (`App\Enums\Admin\QuotaSource`). */
export type QuotaSource = 'youtube' | 'x';

/** Where a post was made (`App\Enums\Post\CreatedVia`). */
export type PostCreatedVia = 'web' | 'api' | 'mcp' | 'repurpose';

/** Where an offer stands (`App\Enums\Offer\OfferState`). */
export type OfferState = 'scheduled' | 'live' | 'full' | 'ended';

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

/** A figure's change from a past value, as the backoffice's tiles show it. */
export interface AdminChange {
    /** The change in percent; its sign picks the arrow. */
    value: number;
    label: string;
    inverted?: boolean;
}

/** One tile of an `AdminStatGrid`: a muted label over a large figure. */
export interface AdminStat {
    key: string;
    label: string;
    value: string;
    hint?: string;
    change?: AdminChange | null;
}

/** One figure of an `AdminMetricTabs` row, picking the chart under it. */
export interface AdminMetricTab<Value extends string = string> {
    value: Value;
    label: string;
    figure: string;
    hint?: string;
    change?: AdminChange | null;
}

/** One label / value row of an `AdminDetailList`. */
export interface AdminDetailRow {
    key: string;
    label: string;
    value?: string;
}

/** One column of an `AdminSortableTable`. */
export interface AdminTableColumn {
    key: string;
    label: string;
    numeric?: boolean;
}

export interface AdminUser {
    id: string;
    name: string;
    email: string;
    photo_url: string | null;
    email_verified: boolean;
    is_backoffice_admin: boolean;
    is_owner: boolean;
    plan: string | null;
    has_app_access: boolean;
    workspaces_count?: number;
    blocked_at: string | null;
    blocked_reason: string | null;
    created_at: string;
}

export interface AdminActivity {
    id: string;
    event: string;
    causer_name: string | null;
    causer_email: string | null;
    properties: Record<string, unknown>;
    created_at: string | null;
}

export interface ScrollPage<T> {
    data: T[];
    meta: { hasNextPage: boolean };
}

export interface AdminPublishingDay {
    date: string;
    published: number;
    failed: number;
}

export interface AdminPublishingCounts {
    published: number;
    failed: number;
    failure_rate: number;
}

export interface AdminPublishingStats {
    days: AdminPublishingDay[];
    totals: AdminPublishingCounts;
    networks: (AdminPublishingCounts & { platform: string })[];
    top_errors: {
        platform: string;
        network: string;
        category: string;
        message: string;
        count: number;
    }[];
    categories: { category: string; count: number }[];
    origins: (AdminPublishingCounts & { origin: string })[];
    content_types: (AdminPublishingCounts & {
        platform: string;
        platform_label: string;
        content_type: string;
        label: string;
    })[];
}

export interface AdminFailedPost {
    id: string;
    platform: string;
    channel_name: string | null;
    channel_username: string | null;
    workspace_name: string | null;
    owner_id: string | null;
    owner_name: string | null;
    channel_status: SocialAccountStatusValue | null;
    content_type: string | null;
    content_type_label: string | null;
    created_via: PostCreatedVia | null;
    content: string;
    media: { url: string | null; type: MediaType | null; mime_type: string | null }[];
    error_message: string | null;
    error_category: string;
    error_context: Record<string, unknown> | null;
    scheduled_at: string | null;
    failed_at: string | null;
    retryable: boolean;
    needs_instagram_confirmation: boolean;
}

export interface AdminNetworkHealth {
    platform: string;
    label: string;
    channels: { connected: number; token_expired: number; disconnected: number };
    last_24h: AdminPublishingCounts;
    last_7d: AdminPublishingCounts;
    last_failure_at: string | null;
}

export interface AdminScopePermission {
    scope: string;
    required: boolean;
    features: GatedFeatureValue[];
}

export interface AdminFeaturePermission {
    feature: GatedFeatureValue;
    enabled: boolean;
    missing: string[];
}

export interface AdminNetworkPermissions {
    platform: string;
    label: string;
    scopes: AdminScopePermission[];
    features: AdminFeaturePermission[];
}

export interface AdminYouTubeQuota {
    metric: string;
    usage: number;
    limits: { name: string; value: number }[];
}

export interface AdminUsageDay {
    date: string;
    usage: number;
}

export interface AdminXUsage {
    project_id: string | null;
    project_usage: number;
    project_cap: number | null;
    cap_reset_day: number | null;
    daily_project_usage: AdminUsageDay[];
    daily_client_app_usage:
        | {
              client_app_id: string | null;
              usage: AdminUsageDay[];
          }[]
        | null;
}

export interface AdminXCredits {
    total_balance: number;
    prepaid_balance: number;
    free_balance: number;
    free_grants: { amount: number; expires_at: string | null }[];
}

export interface AdminQuotaCards {
    youtube: {
        configured: boolean;
        taken_at: string | null;
        since: string | null;
        quotas: AdminYouTubeQuota[];
    };
    x: {
        configured: boolean;
        taken_at: string | null;
        credits: AdminXCredits | null;
        usage: AdminXUsage | null;
        estimate: AdminXCostDay[];
        prices: { read: number; create: number; create_with_link: number };
    };
}

export interface AdminDashboard {
    attention: {
        networks: {
            platform: string;
            label: string;
            network: string;
            failed: number;
            failure_rate: number;
        }[];
        stuck_publishing: number;
        quotas: { source: string; label: string; detail: string }[];
    };
    last_24h: AdminPublishingCounts;
    queue: { scheduled: number; publishing: number; retrying: number; pending_review: number };
    publishing_days: AdminPublishingDay[];
    channels: { total: number; last_7_days: number; connected: number; to_reconnect: number };
}

export interface AdminXCostDay {
    date: string;
    reads: number;
    created: number;
    created_with_link: number;
    cost_reads: number;
    cost_created: number;
    cost_created_with_link: number;
}

/** The open tab of a user's profile and the data the server sent for it. */
export type AdminUserTabData =
    | { tab: 'overview'; data: AdminUserOverview }
    | { tab: 'workspaces'; data: AdminUserWorkspacesData }
    | { tab: 'posts'; data: AdminUserPostsData }
    | { tab: 'integrations'; data: AdminUserIntegrationsData }
    | { tab: 'billing'; data: AdminUserBillingData }
    | { tab: 'activity'; data: AdminUserActivityData };

/** A tab of a user's profile (`App\Enums\Admin\UserTab`). */
export type AdminUserTab = AdminUserTabData['tab'];

export interface AdminUserHealth {
    stage: string;
    last_posted_at: string | null;
    posts: { sent: number; previous: number; automated: number; failed: number; failure_rate: number };
    channels: { total: number; broken: number };
    /** Null on a self-hosted install or for an account that pays nothing now. */
    risk: { signals: ChurnSignal[]; score: number; level: RiskLevel | null; mrr_cents: number } | null;
}

export interface AdminUserOverview {
    sign_in: {
        password: boolean;
        google: boolean;
        github: boolean;
        email_verified_at: string | null;
    };
    signup: { at: string; ip: string | null; locale: string | null; timezone: string | null };
    last_seen: { at: string | null; ip: string | null; user_agent: string | null };
    welcome: {
        persona: string | null;
        goals: string[];
        previous_software: string | null;
        referral_source: string | null;
    };
    attribution: Record<string, string>;
    preferences: {
        theme: string | null;
        time_format: string | null;
        week_starts_on: string | null;
        default_post_action: string | null;
    };
    health: AdminUserHealth | null;
    lifecycle: {
        stage: string | null;
        activated_at: string | null;
        first_paid_at: string | null;
        onboarding_completed_at: string | null;
    };
    links: { stripe: string | null };
}

export interface AdminUserChannel {
    id: string;
    platform: string;
    name: string | null;
    username: string | null;
    avatar_url: string | null;
    status: SocialAccountStatusValue;
    connected_at: string | null;
    last_verified_at: string | null;
    missing_permissions: GatedFeatureValue[];
}

export interface AdminUserWorkspaceDetail {
    id: string;
    name: string;
    is_owner: boolean;
    is_admin: boolean;
    requires_approval: boolean;
    created_at: string;
    members: { id: string; name: string; email: string; is_owner: boolean }[];
    channels: AdminUserChannel[];
}

export interface AdminUserWorkspacesData {
    workspaces: AdminUserWorkspaceDetail[];
    channels_by_network: Record<string, number>;
    pending_invites: { id: string; email: string; is_admin: boolean; created_at: string | null }[];
}

export interface AdminUserPostsData {
    total: number;
    by_status: Record<string, number>;
    by_network: ({ platform: string; total: number } & Record<string, number | string>)[];
    recent_failures: {
        id: string;
        platform: string | null;
        channel_name: string | null;
        workspace_name: string | null;
        error_message: string | null;
        failed_at: string | null;
    }[];
    content: Record<string, number>;
}

export interface AdminUserIntegrationsData {
    api_keys: {
        id: string;
        name: string | null;
        workspace_name: string | null;
        revoked: boolean;
        created_at: string | null;
        last_used_at: string | null;
        expires_at: string | null;
    }[];
    mcp_apps: {
        id: string;
        client: string | null;
        workspace_name: string | null;
        created_at: string | null;
        last_used_at: string | null;
    }[];
    webhooks: {
        id: string;
        endpoint: string;
        events: string[];
        status: string | null;
        workspace_name: string | null;
        consecutive_failures: number;
        last_sent_at: string | null;
    }[];
    media_sources: { id: string; source: string | null; connected_at: string | null }[];
}

/** The billing of the account a user belongs to, as `BuildUserBilling` reads it. */
export interface AdminAccountBilling {
    account: {
        id: string;
        name: string | null;
        is_owner: boolean;
        stripe_id: string | null;
        stripe_url: string | null;
        has_app_access: boolean;
        generic_trial_ends_at: string | null;
    };
    plan: string | null;
    subscription: {
        status: string;
        interval: string | null;
        started_at: string | null;
        trial_ends_at: string | null;
        cancels_at: string | null;
        on_grace_period: boolean;
        past_due: boolean;
    } | null;
    offers: { slug: string; claimed_at: string | null; redeemed_at: string | null }[];
    /** Null for an account Stripe has no customer for. */
    stripe: {
        cards: {
            id: string;
            brand: string;
            last4: string;
            exp_month: number;
            exp_year: number;
            is_default: boolean;
        }[];
        discount: {
            name: string | null;
            percent_off: number | null;
            amount_off: string | null;
            duration: string | null;
            ends_at: string | null;
            promotion_code: string | null;
        } | null;
        used_discounts: { name: string | null; amount_off: string; invoice_date: string }[];
        invoices: {
            id: string;
            date: string;
            total: string;
            status: string;
            invoice_pdf: string | null;
        }[];
        error: string | null;
    } | null;
}

/** A user's Billing tab: their account's billing, or no account at all. */
export type AdminUserBillingData = AdminAccountBilling | { account: null };

export interface AdminUserActivityData {
    history: AdminActivity[];
    sessions: {
        id: string;
        user_agent: string | null;
        ip: string | null;
        last_activity: string;
    }[];
    changelog: {
        opened: number;
        liked: number;
        disliked: number;
        entries: AdminChangelogView[];
    };
}

export interface AdminChangelogView {
    viewed_at: string | null;
    entry: ChangelogEntry;
}

export interface AdminChannelGrowth {
    days: string[];
    by_network: Record<string, number[]>;
    total_by_day: number[];
    new_in_range: number;
    connected_now: number;
}

export interface AdminSubscriptionPlan {
    plan: string;
    name: string;
    interval: string;
}

export interface AdminSubscriptionOverview {
    previous: { new: number; canceled: number; churn_rate: number; mrr_cents: number };
    now: { alive: number; active: number; past_due: number; trialing: number; cancelling: number };
    period: { new: number; canceled: number; alive_at_start: number; churn_rate: number };
    days: string[];
    alive_by_day: number[];
    new_by_day: number[];
    canceled_by_day: number[];
    churn_rate_by_day: number[];
    plans: (AdminSubscriptionPlan & { count: number })[];
    mrr: { cents: number; subscriptions: number; unpriced: number };
    at_risk: { past_due_cents: number; cancelling_cents: number };
    offers: { slug: string; redeemed: number; still_subscribed: number }[];
}

export interface AdminSubscriptionChurn {
    summary: { canceled: number; churn_rate: number; average_lifetime_days: number; cancelling: number };
    days: string[];
    canceled_by_day: number[];
    churn_rate_by_day: number[];
    recent: (AdminSubscription & { lifetime_days: number })[];
    lifetimes: { label: string; count: number }[];
    cohorts: { month: string; started: number; alive: number; retention: number }[];
}

export interface AdminSubscription {
    id: string;
    user_id: string | null;
    user_name: string | null;
    user_email: string | null;
    account_name: string | null;
    plan: AdminSubscriptionPlan;
    quantity: number;
    state: string;
    started_at: string | null;
    trial_ends_at: string | null;
    ends_at: string | null;
    offers: string[];
}

export type AdminNetMrr =
    | {
          cents: number;
          list_cents: number;
          subscriptions: number;
          discounted: number;
          taken_at: string;
      }
    | { error: string };

export interface AdminOffer {
    id: string;
    slug: string;
    stripe_coupon_id: string | null;
    trial_days: number | null;
    starts_at: string | null;
    ends_at: string | null;
    starts_on: string | null;
    ends_on: string | null;
    max_redemptions: number | null;
    allow_promotion_codes: boolean;
    first_subscription_only: boolean;
    show_banner: boolean;
    requires_card: boolean;
    state: OfferState;
    claimed: number;
    redeemed: number;
    missing_copy: string[];
}

export interface AdminBreakdownRow {
    value: string;
    label: string;
    count: number;
    subscribed: number;
    subscribed_rate: number;
}

export interface AdminSignupTotals {
    signups: number;
    subscribed: number;
    subscribed_rate: number;
    new_mrr_cents: number;
    /** The median from signing up to subscribing; null when nobody subscribed. */
    minutes_to_subscribe: number | null;
}

export interface AdminSignupInsights {
    totals: AdminSignupTotals;
    previous: AdminSignupTotals;
    days: string[];
    signups_by_day: number[];
    subscribed_by_day: number[];
    funnel: { key: string; label: string; count: number }[];
    personas: AdminBreakdownRow[];
    software: AdminBreakdownRow[];
    goals: AdminBreakdownRow[];
    referral_sources: AdminBreakdownRow[];
    signup_methods: AdminBreakdownRow[];
    answered: { persona: number; software: number; goals: number; referral_source: number };
}

export interface AdminBarRow {
    key: string;
    label: string;
    count: number;
    /** The row's share of the whole, in percent; a row without it shows no share. */
    share?: number;
    /** A network's row shows its logo. */
    platform?: string;
    /** A short note shown before the count. */
    hint?: string;
    /** The row links to this page. */
    href?: string;
    /** The bar's length in percent, when it is not measured against the largest count (a rate, a share). */
    bar?: number;
    /** The bar's color class, when it is not the brand color. */
    barClass?: string;
}

export interface AdminChannelDistribution {
    totals: { channels: number; accounts: number; first: number };
    channels: AdminBarRow[];
    accounts: (AdminBarRow & { per_account: number })[];
    first: AdminBarRow[];
}

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
        score: number;
        level: RiskLevel;
    })[];
}
