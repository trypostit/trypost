export const BILLING_INTERVALS = ['monthly', 'yearly'] as const;

export type BillingInterval = (typeof BILLING_INTERVALS)[number];

export const DEFAULT_BILLING_INTERVAL: BillingInterval = 'monthly';

export interface PlanOption {
    id: string;
    slug: string;
    name: string;
    workspace_limit: number | null;
}

export const deniedPlanIdsFor = (
    plans: PlanOption[],
    workspaceCount: number,
): string[] =>
    plans
        .filter(
            (plan) =>
                plan.workspace_limit !== null &&
                workspaceCount > plan.workspace_limit,
        )
        .map((plan) => plan.id);

export interface AuthPlan {
    id: string;
    slug: string;
    name: string;
    interval: BillingInterval;
}

export interface Features {
    workspaceLimit: number | null;
}
