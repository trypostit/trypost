<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import SettingsTabsNav from '@/components/settings/SettingsTabsNav.vue';
import WorkspaceTab from '@/components/settings/WorkspaceTab.vue';
import { useWorkspaceSettingsTabs } from '@/composables/useWorkspaceSettingsTabs';
import AppLayout from '@/layouts/AppLayout.vue';

interface Workspace {
    id: string;
    name: string;
    has_logo: boolean;
    logo_url: string | null;
    brand_website: string | null;
    brand_description: string | null;
    brand_voice_traits: string[] | null;
    content_language: string;
}

defineProps<{
    workspace: Workspace;
    isOnlyWorkspace: boolean;
    otherMemberCount: number;
}>();

const tabs = useWorkspaceSettingsTabs();
</script>

<template>
    <Head :title="$t('settings.workspace.title')" />

    <AppLayout :title="$t('settings.hub.title')">
        <div class="mx-auto max-w-4xl space-y-8 px-6 py-8">
            <p class="text-sm text-muted-foreground">
                {{ $t('settings.hub.description') }}
            </p>

            <SettingsTabsNav :tabs="tabs" active="workspace" />

            <WorkspaceTab
                :workspace="workspace"
                :is-only-workspace="isOnlyWorkspace"
                :other-member-count="otherMemberCount"
            />
        </div>
    </AppLayout>
</template>
