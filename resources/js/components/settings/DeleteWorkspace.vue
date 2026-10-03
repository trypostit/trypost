<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { IconAlertTriangle } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
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

const warningKey = computed((): string => {
    if (props.isOnlyWorkspace) {
        return 'settings.workspace.delete_only_description';
    }

    return isSelfHosted.value
        ? 'settings.workspace.delete_description_self_hosted'
        : 'settings.workspace.delete_description';
});

const confirmKey = computed((): string =>
    isSelfHosted.value
        ? 'settings.workspace.delete_confirm_description_self_hosted'
        : 'settings.workspace.delete_confirm_description',
);

const showMembersWarning = computed(
    (): boolean => !props.isOnlyWorkspace && props.otherMemberCount > 0,
);

const openDeleteModal = () => {
    deleteModal.value?.open({
        url: destroyWorkspace.url(props.workspace.id),
        confirmText: props.workspace.name,
    });
};
</script>

<template>
    <SettingsSection
        :title="$t('settings.workspace.delete_title')"
        :description="$t('settings.workspace.danger_description')"
    >
        <template v-if="!isOnlyWorkspace" #actions>
            <Button variant="destructive" @click="openDeleteModal">
                {{ $t('settings.workspace.delete_action') }}
            </Button>
        </template>

        <div
            class="flex items-start gap-2 rounded-xl bg-critical-subtle p-4 text-sm text-foreground"
        >
            <IconAlertTriangle
                class="mt-0.5 size-4 shrink-0 text-destructive-text"
            />
            <div class="flex flex-col gap-1">
                <p class="font-emphasis">
                    {{ $t('settings.workspace.delete_warning') }}
                </p>
                <p>
                    {{ $t(warningKey) }}
                    <template v-if="showMembersWarning">
                        {{
                            $tChoice(
                                'settings.workspace.delete_members_warning',
                                otherMemberCount,
                                { count: String(otherMemberCount) },
                            )
                        }}
                    </template>
                </p>
            </div>
        </div>

        <div v-if="isOnlyWorkspace" class="flex flex-wrap gap-2">
            <Button variant="outline" as-child>
                <Link :href="billingIndex()">
                    {{ $t('settings.workspace.delete_go_to_billing') }}
                </Link>
            </Button>
            <Button variant="destructive" as-child>
                <Link :href="editAuthentication()">
                    {{ $t('settings.workspace.delete_go_to_delete_account') }}
                </Link>
            </Button>
        </div>

        <ConfirmDeleteModal
            ref="deleteModal"
            :title="$t('settings.workspace.delete_confirm_title')"
            :description="
                otherMemberCount > 0
                    ? `${$t(confirmKey)} ${$tChoice('settings.workspace.delete_members_warning', otherMemberCount, { count: String(otherMemberCount) })}`
                    : $t(confirmKey)
            "
            :action="$t('settings.workspace.delete_action')"
            :cancel="$t('settings.workspace.delete_cancel')"
        />
    </SettingsSection>
</template>
