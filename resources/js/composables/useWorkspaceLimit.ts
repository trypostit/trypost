import { router, useHttp, usePage } from '@inertiajs/vue3';
import { computed, toValue, type MaybeRefOrGetter } from 'vue';

import {
    create as createWorkspaceRoute,
    limitHit as limitHitRoute,
} from '@/routes/app/workspaces';
import type { SharedData } from '@/types';

export const useWorkspaceLimit = (
    workspaceCount: MaybeRefOrGetter<number>,
) => {
    const page = usePage<SharedData>();
    const limitHitHttp = useHttp<Record<string, never>, null>({});

    const workspaceLimit = computed(
        (): number | null => page.props.features?.workspaceLimit ?? null,
    );

    const resolvedCount = computed(
        (): number =>
            page.props.usage?.workspaceCount ?? toValue(workspaceCount),
    );

    const atWorkspaceLimit = computed((): boolean => {
        const limit = workspaceLimit.value;

        return limit !== null && resolvedCount.value >= limit;
    });

    const requireUpgrade = (onUpgradeRequired: () => void): void => {
        limitHitHttp.post(limitHitRoute.url()).catch(() => undefined);

        onUpgradeRequired();
    };

    const createOrUpgrade = (onUpgradeRequired: () => void): void => {
        if (atWorkspaceLimit.value) {
            requireUpgrade(onUpgradeRequired);

            return;
        }

        router.visit(createWorkspaceRoute.url());
    };

    return {
        workspaceLimit,
        atWorkspaceLimit,
        createOrUpgrade,
        requireUpgrade,
    };
};
