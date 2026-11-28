import { router, useHttp, usePage } from '@inertiajs/vue3';
import { computed, toValue, type MaybeRefOrGetter } from 'vue';

import { create as createWorkspaceRoute } from '@/routes/app/workspaces';
import { store as checkWorkspaceCreation } from '@/routes/app/workspaces/creation-check';
import type { SharedData } from '@/types';

interface WorkspaceCreationCheck {
    allowed: boolean;
}

/**
 * The shared props answer whether the account is at its workspace cap on the
 * create page; creating from a menu asks the server, which opens the upgrade
 * dialog where the user is at the cap and the create page otherwise.
 */
export const useWorkspaceLimit = (
    workspaceCount: MaybeRefOrGetter<number>,
) => {
    const page = usePage<SharedData>();
    const http = useHttp<Record<string, never>, WorkspaceCreationCheck>({});

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

    const visitCreatePage = (): void => {
        router.visit(createWorkspaceRoute.url());
    };

    const createOrUpgrade = async (
        onUpgradeRequired: () => void,
    ): Promise<void> => {
        if (http.processing) {
            return;
        }

        const check = await http
            .post(checkWorkspaceCreation.url())
            .catch(() => null);

        if (check?.allowed === false) {
            onUpgradeRequired();

            return;
        }

        visitCreatePage();
    };

    return {
        workspaceLimit,
        atWorkspaceLimit,
        createOrUpgrade,
    };
};
