import { humanize } from '@/lib/adminFormat';
import { filled } from '@/lib/helpers';
import type { AdminSubscriptionPlan, BadgeVariant } from '@/types/admin';

/** How the backoffice shows where a subscription stands. */
const STATES: Record<string, { label: string; variant: BadgeVariant }> = {
    active: { label: 'Active', variant: 'success' },
    trialing: { label: 'Trialing', variant: 'info' },
    past_due: { label: 'Past due', variant: 'destructive-subtle' },
    unpaid: { label: 'Unpaid', variant: 'destructive-subtle' },
    incomplete: { label: 'Incomplete', variant: 'destructive-subtle' },
    incomplete_expired: { label: 'Incomplete expired', variant: 'destructive-subtle' },
    cancelling: { label: 'Cancelling', variant: 'warning' },
    canceled: { label: 'Canceled', variant: 'secondary' },
};

/** Stripe statuses where the last payment did not go through. */
export const UNPAID_STATES = ['past_due', 'unpaid', 'incomplete', 'incomplete_expired'];

export const subscriptionState = (state: string): { label: string; variant: BadgeVariant } => {
    const known = STATES[state];

    if (filled(known)) {
        return known;
    }

    return { label: humanize(state), variant: 'secondary' };
};

/** The billing intervals a plan's label names. */
const KNOWN_INTERVALS = ['monthly', 'yearly'];

/** "Socials · monthly", or the plan's name alone when the interval is unknown (legacy prices). */
export const subscriptionPlanLabel = (plan: AdminSubscriptionPlan): string => {
    if (KNOWN_INTERVALS.includes(plan.interval)) {
        return `${plan.name} · ${plan.interval}`;
    }

    return plan.name;
};
