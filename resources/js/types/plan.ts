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

export const PLAN_CHANGE_ACTIONS = ['upgrade', 'downgrade', 'select'] as const;

export type PlanChangeAction = (typeof PLAN_CHANGE_ACTIONS)[number];

export const PLAN_CHANGE_LABELS = {
    upgrade: 'billing.plans.upgrade',
    downgrade: 'billing.plans.downgrade',
    select: 'billing.plans.select',
} as const satisfies Record<
    PlanChangeAction,
    `billing.plans.${PlanChangeAction}`
>;

const workspaceRank = (plan: PlanOption): number =>
    plan.workspace_limit === null
        ? Number.POSITIVE_INFINITY
        : plan.workspace_limit;

export const planChangeAction = (
    current: PlanOption | null | undefined,
    target: PlanOption,
): PlanChangeAction => {
    if (current === null || current === undefined || current.id === target.id) {
        return 'select';
    }

    const from = workspaceRank(current);
    const to = workspaceRank(target);

    if (to > from) {
        return 'upgrade';
    }

    if (to < from) {
        return 'downgrade';
    }

    return 'select';
};

export interface AuthPlan {
    id: string;
    slug: string;
    name: string;
    interval: BillingInterval;
}

export interface Features {
    workspaceLimit: number | null;
}
