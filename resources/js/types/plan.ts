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
    interval: 'monthly' | 'yearly';
}

export interface Features {
    workspaceLimit: number | null;
}
