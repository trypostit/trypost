export interface PlanOption {
    id: string;
    slug: string;
    name: string;
    workspace_limit: number | null;
}

/** The account's current plan as shared on `auth.plan`. */
export interface AuthPlan {
    id: string;
    slug: string;
    name: string;
    interval: 'monthly' | 'yearly';
}

export interface Features {
    workspaceLimit: number | null;
}
