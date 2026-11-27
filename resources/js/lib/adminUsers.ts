import { filled } from '@/lib/helpers';
import { show as userShow } from '@/routes/admin/users';

/** A customer's name, or what the backoffice calls one whose user was deleted. */
export const customerName = (name: string | null): string => {
    if (filled(name)) {
        return name;
    }

    return 'Deleted user';
};

/** A user's profile, opened on its Billing tab. */
export const billingUrl = (userId: string): string =>
    userShow.url(userId, { query: { tab: 'billing' } });
