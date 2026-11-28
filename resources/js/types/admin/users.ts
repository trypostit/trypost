import type { ChurnSignal, RiskLevel } from '@/types/admin/userInsights';
import type { ChangelogEntry } from '@/types/changelog';
import type { GatedFeatureValue } from '@/types/channel-permission';
import type { SocialAccountStatusValue } from '@/types/social-account-status';

/** The Users list's tabs (`App\Enums\User\UserStatusFilter`). */
export type UserStatusFilter = 'all' | 'blocked';

/** Where a webhook stands (`App\Enums\Webhook\Status`). */
export type WebhookStatus = 'enabled' | 'disabled' | 'paused';

export interface AdminUser {
    id: string;
    name: string;
    email: string;
    photo_url: string | null;
    email_verified: boolean;
    can_access_admin: boolean;
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

/** The open tab of a user's profile and the data the server sent for it. */
export type AdminUserTabData =
    | { tab: 'overview'; data: AdminUserOverview }
    | { tab: 'workspaces'; data: AdminUserWorkspacesData }
    | { tab: 'posts'; data: AdminUserPostsData }
    | { tab: 'integrations'; data: AdminUserIntegrationsData }
    | { tab: 'billing'; data: AdminUserBillingData }
    | { tab: 'activity'; data: AdminUserActivityData };

/** A tab of a user's profile (`App\Enums\User\UserProfileTab`). */
export type AdminUserTab = AdminUserTabData['tab'];

export interface AdminUserHealth {
    stage: string;
    last_posted_at: string | null;
    posts: { sent: number; previous: number; automated: number; failed: number; failure_rate: number };
    channels: { total: number; broken: number };
    /** Null on a self-hosted install or for an account that pays nothing now. */
    risk: { signals: ChurnSignal[]; level: RiskLevel | null; mrr_cents: number } | null;
}

export interface AdminUserOverview {
    sign_in: {
        password: boolean;
        google: boolean;
        github: boolean;
        email_verified_at: string | null;
    };
    signup: { at: string; ip: string | null };
    last_seen: { at: string | null; ip: string | null; user_agent: string | null };
    welcome: {
        persona: string | null;
        goals: string[];
        previous_software: string | null;
        referral_source: string | null;
    };
    attribution: Record<string, string>;
    preferences: {
        locale: string | null;
        timezone: string | null;
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
        status: WebhookStatus | null;
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
