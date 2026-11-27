<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import WorkspaceTab from '@/components/settings/WorkspaceTab.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';

interface Workspace {
    id: string;
    name: string;
    has_logo: boolean;
    logo_url: string | null;
}

defineProps<{
    workspace: Workspace;
    isOnlyWorkspace: boolean;
    otherMemberCount: number;
}>();
</script>

<template>
    <Head :title="$t('settings.workspace.title')" />

    <SettingsLayout :title="$t('settings.workspace.title')">
        <WorkspaceTab
            :workspace="workspace"
            :is-only-workspace="isOnlyWorkspace"
            :other-member-count="otherMemberCount"
        />
    </SettingsLayout>
</template>
