import { filled } from '@/lib/helpers';
import type { AdminSubscriptionPlan } from '@/types/admin';

type BadgeVariant = 'success' | 'info' | 'warning' | 'destructive-subtle' | 'secondary';

/** How the backoffice shows where a subscription stands. */
const STATES: Record<string, { label: string; variant: BadgeVariant }> = {
    active: { label: 'Active', variant: 'success' },
    trialing: { label: 'Trialing', variant: 'info' },
    past_due: { label: 'Past due', variant: 'destructive-subtle' },
    unpaid: { label: 'Unpaid', variant: 'destructive-subtle' },
    cancelling: { label: 'Cancelling', variant: 'warning' },
    canceled: { label: 'Canceled', variant: 'secondary' },
};

export const subscriptionState = (state: string): { label: string; variant: BadgeVariant } => {
    const known = STATES[state];

    if (filled(known)) {
        return known;
    }

    return { label: state, variant: 'secondary' };
};

const INTERVALS: Record<string, string> = { monthly: 'monthly', yearly: 'yearly' };

/** "Socials · monthly", or the plan's name alone when the interval is unknown (legacy prices). */
export const planLabel = (plan: AdminSubscriptionPlan): string => {
    const interval = INTERVALS[plan.interval];

    if (filled(interval)) {
        return `${plan.name} · ${interval}`;
    }

    return plan.name;
};

export const usd = (cents: number): string =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(cents / 100);
