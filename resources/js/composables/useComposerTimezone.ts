import { computed, type ComputedRef } from 'vue';

import { userTimezone } from '@/preferences';

/**
 * The zone the composer shows and takes times in: the channels' zone when they
 * all share one, the user's zone when they differ or none is selected.
 */
export const useComposerTimezone = (
    accounts: () => { timezone?: string }[],
): ComputedRef<string> =>
    computed(() => {
        const zones = [
            ...new Set(
                accounts()
                    .map((account) => account.timezone)
                    .filter((zone): zone is string => Boolean(zone)),
            ),
        ];

        return zones.length === 1 ? zones[0] : userTimezone.value;
    });
