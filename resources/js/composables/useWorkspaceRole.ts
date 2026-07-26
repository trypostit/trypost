import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { WorkspaceRole } from '@/types/workspace-role';

/**
 * Role-based capability flags for the current workspace, mirroring the
 * backend `WorkspacePolicy` so UI affordances only render for roles that can
 * actually perform the action. Reads `auth.currentWorkspace.role`.
 */
export const useWorkspaceRole = () => {
    const page = usePage();

    const role = computed<string | null>(
        () => (page.props.auth?.currentWorkspace?.role as string | null) ?? null,
    );

    const isOwner = computed(() => role.value === WorkspaceRole.Owner);
    const isAdminOrAbove = computed(
        () => isOwner.value || role.value === WorkspaceRole.Admin,
    );
    const isMemberOrAbove = computed(
        () => isAdminOrAbove.value || role.value === WorkspaceRole.Member,
    );

    return {
        role,
        isOwner,
        isAdminOrAbove,
        isMemberOrAbove,
        canCreatePost: isMemberOrAbove,
        canManageAutomations: isMemberOrAbove,
        canManageAccounts: isAdminOrAbove,
        canManageTeam: isAdminOrAbove,
        canManageWorkspace: isAdminOrAbove,
        canManageBilling: isOwner,
        canCreateWorkspace: isOwner,
    };
};
