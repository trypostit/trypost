import { isNumber } from '@/lib/helpers';
import { formatPercentChange, percentChange } from '@/lib/utils';
import type { AdminChange } from '@/types/admin/shared';

/**
 * A figure's change from a past value, as the admin's stat tiles show
 * it: signed, one decimal. `inverted` when going up is bad news (cancellations,
 * churn, a longer wait). None when there is nothing to compare with.
 */
export const metricChange = (current: number, past: number, inverted = false): AdminChange | null => {
    const value = percentChange(current, past);

    if (isNumber(value)) {
        return { value, label: formatPercentChange(value, 1), inverted };
    }

    return null;
};
