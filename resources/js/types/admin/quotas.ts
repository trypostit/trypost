/** A network whose quota the admin reads (`App\Enums\Quota\QuotaSource`). */
export type QuotaSource = 'youtube' | 'x';

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

export interface AdminXCostDay {
    date: string;
    reads: number;
    created: number;
    created_with_link: number;
    cost_reads: number;
    cost_created: number;
    cost_created_with_link: number;
}
