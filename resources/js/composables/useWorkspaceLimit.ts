import { router, usePage } from '@inertiajs/vue3';
import { computed, toValue, type MaybeRefOrGetter } from 'vue';

import { create as createWorkspaceRoute } from '@/routes/app/workspaces';
import type { SharedData } from '@/types';

export const useWorkspaceLimit = (
    workspaceCount: MaybeRefOrGetter<number>,
) => {
    const page = usePage<SharedData>();

    const workspaceLimit = computed(
        (): number | null => page.props.features?.workspaceLimit ?? null,
    );

    const atWorkspaceLimit = computed((): boolean => {
        const limit = workspaceLimit.value;

        return limit !== null && toValue(workspaceCount) >= limit;
    });

    const createOrUpgrade = (onUpgradeRequired: () => void): void => {
        if (atWorkspaceLimit.value) {
            onUpgradeRequired();

            return;
        }

        router.visit(createWorkspaceRoute.url());
    };

    return {
        workspaceLimit,
        atWorkspaceLimit,
        createOrUpgrade,
    };
};
