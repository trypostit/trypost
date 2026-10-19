import { router, useHttp } from '@inertiajs/vue3';
import { computed } from 'vue';

import { create as createWorkspaceRoute } from '@/routes/app/workspaces';
import { store as checkWorkspaceCreation } from '@/routes/app/workspaces/creation-check';

interface WorkspaceCreationCheck {
    allowed: boolean;
}

/**
 * The server decides whether another workspace fits the plan. At the cap the
 * upgrade dialog opens where the user is; otherwise the create page opens.
 */
export const useWorkspaceLimit = () => {
    const http = useHttp<Record<string, never>, WorkspaceCreationCheck>({});

    const checking = computed((): boolean => http.processing);

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
        checking,
        createOrUpgrade,
    };
};
