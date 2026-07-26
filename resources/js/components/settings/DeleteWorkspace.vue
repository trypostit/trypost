<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { IconTrash } from '@tabler/icons-vue';
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

const description = computed(() => {
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
    <section class="space-y-4" dusk="workspace-danger-zone">
        <HeadingSmall
            :title="$t('settings.workspace.danger_title')"
            :description="$t('settings.workspace.danger_description')"
        />

        <div
            class="flex items-center justify-between gap-4 rounded-xl border-2 border-destructive/30 bg-destructive/5 p-4"
        >
            <div class="text-sm">
                <p class="font-medium">
                    {{ $t('settings.workspace.delete_title') }}
                </p>
                <p class="text-foreground/60">
                    {{ $t(description) }}
                </p>
            </div>
            <Button
                v-if="!isOnlyWorkspace"
                variant="destructive"
                dusk="workspace-delete"
                @click="openDeleteModal"
            >
                <IconTrash class="size-4" />
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
    </section>
</template>
