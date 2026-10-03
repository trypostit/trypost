import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Capability flags for the current workspace, mirroring the backend
 * `WorkspacePolicy` abilities. Reads `auth.currentWorkspace`.
 */
export const useWorkspaceAbilities = () => {
    const page = usePage();

    const workspace = computed(() => page.props.auth?.currentWorkspace ?? null);
    const isOwner = computed(() => workspace.value?.is_owner === true);
    const canManageWorkspace = computed(
        () => isOwner.value || workspace.value?.is_admin === true,
    );
    const requiresApproval = computed(
        () => !isOwner.value && workspace.value?.requires_approval === true,
    );
    const canPublishDirectly = computed(
        () => workspace.value !== null && !requiresApproval.value,
    );
    const canCreatePost = computed(() => workspace.value !== null);

    return {
        isOwner,
        canManageWorkspace,
        canPublishDirectly,
        canApprove: canPublishDirectly,
        requiresApproval,
        canCreatePost,
        canManageRepurposes: canPublishDirectly,
        canManageAccounts: canManageWorkspace,
        canManageWebhooks: canManageWorkspace,
        canManageTeam: canManageWorkspace,
        canManageBilling: isOwner,
        canCreateWorkspace: isOwner,
    };
};
