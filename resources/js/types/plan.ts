export type BillingInterval = 'monthly' | 'yearly';

export interface PlanOption {
    id: string;
    slug: string;
    name: string;
    workspace_limit: number | null;
}

export interface AuthPlan {
    id: string;
    slug: string;
    name: string;
    interval: BillingInterval;
}

export interface Features {
    workspaceLimit: number | null;
}
