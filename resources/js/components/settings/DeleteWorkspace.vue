<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { edit as editAuthentication } from '@/routes/app/authentication';
import { index as billingIndex } from '@/routes/app/billing';
import { destroy as destroyWorkspace } from '@/routes/app/workspaces';

const props = defineProps<{
    workspace: {
        id: string;
        name: string;
    };
    isOnlyWorkspace: boolean;
    otherMemberCount: number;
}>();

const page = usePage();
const isSelfHosted = computed(() => Boolean(page.props.selfHosted));

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const membersWarning = computed(() => {
    if (props.otherMemberCount <= 0) {
        return null;
    }

    return transChoice(
        'settings.workspace.delete_members_warning',
        props.otherMemberCount,
        { count: String(props.otherMemberCount) },
    );
});

const warningMessage = computed(() => {
    if (props.isOnlyWorkspace) {
        return trans('settings.workspace.delete_only_description');
    }

    const base = isSelfHosted.value
        ? trans('settings.workspace.delete_description_self_hosted')
        : trans('settings.workspace.delete_description');

    return membersWarning.value ? `${base} ${membersWarning.value}` : base;
});

const confirmDescription = computed(() => {
    const base = isSelfHosted.value
        ? trans('settings.workspace.delete_confirm_description_self_hosted')
        : trans('settings.workspace.delete_confirm_description');

    return membersWarning.value ? `${base} ${membersWarning.value}` : base;
});

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
                <p class="font-bold">{{ $t('settings.workspace.delete_warning') }}</p>
                <p class="text-sm font-medium">
                    {{ warningMessage }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="!isOnlyWorkspace"
                    variant="destructive"
                    dusk="workspace-delete"
                    @click="openDeleteModal"
                >
                    {{ $t('settings.workspace.delete_action') }}
                </Button>
                <template v-else>
                    <Button
                        variant="outline"
                        as-child
                        dusk="workspace-delete-billing-link"
                    >
                        <Link :href="billingIndex()">
                            {{ $t('settings.workspace.delete_go_to_billing') }}
                        </Link>
                    </Button>
                    <Button
                        variant="destructive"
                        as-child
                        dusk="workspace-delete-account-link"
                    >
                        <Link :href="editAuthentication()">
                            {{ $t('settings.workspace.delete_go_to_delete_account') }}
                        </Link>
                    </Button>
                </template>
            </div>
        </div>

        <ConfirmDeleteModal
            ref="deleteModal"
            :title="$t('settings.workspace.delete_confirm_title')"
            :description="confirmDescription"
            :action="$t('settings.workspace.delete_action')"
            :cancel="$t('settings.workspace.delete_cancel')"
        />
    </div>
</template>
