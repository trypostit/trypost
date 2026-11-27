import { filled } from '@/lib/helpers';
import { show as userShow } from '@/routes/admin/users';

/** A customer's name, or what the backoffice calls one whose user was deleted. */
export const customerName = (name: string | null): string => {
    if (filled(name)) {
        return name;
    }

    return 'Deleted user';
};

/** The name of the plan a user's account is on, or "No plan". */
export const planName = (plan: string | null): string => {
    if (filled(plan)) {
        return plan;
    }

    return 'No plan';
};

/** A user's profile, on its first tab. */
export const profileUrl = (userId: string): string => userShow.url(userId);

/** A user's profile, opened on its Billing tab. */
export const billingUrl = (userId: string): string =>
    userShow.url(userId, { query: { tab: 'billing' } });
