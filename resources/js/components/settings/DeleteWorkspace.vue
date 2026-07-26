<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { useWorkspaceRole } from '@/composables/useWorkspaceRole';
import { index as billingIndex } from '@/routes/app/billing';
import { destroy as destroyWorkspace } from '@/routes/app/workspaces';

const props = defineProps<{
    workspace: {
        id: string;
        name: string;
    };
    isOnlyWorkspace: boolean;
}>();

const page = usePage();
const isSelfHosted = computed(() => Boolean(page.props.selfHosted));
const { canManageBilling } = useWorkspaceRole();

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const warningMessage = computed(() => {
    if (props.isOnlyWorkspace) {
        return 'settings.workspace.delete_only_description';
    }

    if (isSelfHosted.value) {
        return 'settings.workspace.delete_description_self_hosted';
    }

    return 'settings.workspace.delete_description';
});

const confirmDescription = computed(() =>
    isSelfHosted.value
        ? 'settings.workspace.delete_confirm_description_self_hosted'
        : 'settings.workspace.delete_confirm_description',
);

const openDeleteModal = () => {
    deleteModal.value?.open({
        url: destroyWorkspace.url(props.workspace.id),
        confirmText: props.workspace.name,
    });
};
</script>

<template>
    <div class="space-y-6" dusk="workspace-danger-zone">
        <HeadingSmall
            :title="$t('settings.workspace.delete_title')"
            :description="$t('settings.workspace.danger_description')"
        />

        <div class="space-y-4 rounded-xl border-2 border-foreground bg-rose-50 p-4 shadow-2xs">
            <div class="relative space-y-0.5 text-rose-700">
                <p class="font-bold">{{ $t('settings.delete_account.warning') }}</p>
                <p class="text-sm font-medium">
                    {{ $t(warningMessage) }}
                </p>
            </div>

            <Button
                v-if="!isOnlyWorkspace"
                variant="destructive"
                dusk="workspace-delete"
                @click="openDeleteModal"
            >
                {{ $t('settings.workspace.delete_action') }}
            </Button>
            <Button
                v-else-if="canManageBilling"
                variant="outline"
                as-child
                dusk="workspace-delete-billing-link"
            >
                <Link :href="billingIndex()">
                    {{ $t('settings.workspace.delete_go_to_billing') }}
                </Link>
            </Button>
        </div>

        <ConfirmDeleteModal
            ref="deleteModal"
            :title="$t('settings.workspace.delete_confirm_title')"
            :description="$t(confirmDescription)"
            :action="$t('settings.workspace.delete_action')"
            :cancel="$t('settings.workspace.delete_cancel')"
        />
    </div>
</template>
