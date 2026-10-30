import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import type { SidebarChannel } from '@/types/channel';
import type { VerifiedBadge } from '@/types/social-account';

/**
 * The verified badge of each connected channel, by account id, read from the
 * shared sidebar channels so Insights shows the same badge as the sidebar
 * without carrying it in the analytics report.
 */
export const useVerifiedBadges = () => {
    const page = usePage();
    const badges = computed(
        () =>
            new Map(
                ((page.props.channels as SidebarChannel[] | undefined) ?? []).map(
                    (channel) => [channel.id, channel.verified_badge ?? null],
                ),
            ),
    );

    return (accountId: string): VerifiedBadge | null =>
        badges.value.get(accountId) ?? null;
};
