<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import PageHeader from '@/components/PageHeader.vue';
import BrandTab from '@/components/settings/BrandTab.vue';
import SettingsTabsNav from '@/components/settings/SettingsTabsNav.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { members as membersRoute } from '@/routes/app';
import { index as apiKeysRoute } from '@/routes/app/api-keys';
import { brand as brandRoute, settings as workspaceSettings } from '@/routes/app/workspace';
import type { ContentLanguageOption } from '@/types';

interface Workspace {
    id: string;
    name: string;
    brand_website: string | null;
    brand_description: string | null;
    brand_voice_traits: string[] | null;
    brand_color: string | null;
    background_color: string | null;
    text_color: string | null;
    brand_font: string;
    image_style: string;
    content_language: string;
}

defineProps<{
    workspace: Workspace;
    availableFonts: string[];
    availableImageStyles: string[];
    availableVoiceTraits: Record<string, string[]>;
    availableContentLanguages: ContentLanguageOption[];
}>();

const tabs = computed(() => [
    { name: 'workspace', label: trans('settings.workspace.tabs.workspace'), href: workspaceSettings.url() },
    { name: 'brand', label: trans('settings.workspace.tabs.brand'), href: brandRoute.url() },
    { name: 'members', label: trans('settings.workspace.tabs.users'), href: membersRoute.url() },
    { name: 'api-keys', label: trans('settings.workspace.tabs.api_keys'), href: apiKeysRoute.url() },
]);
</script>

<template>
    <Head :title="$t('settings.workspace.tabs.brand')" />

    <AppLayout>
        <div class="mx-auto max-w-4xl space-y-8 px-6 py-8">
            <PageHeader
                :title="$t('settings.hub.title')"
                :description="$t('settings.hub.description')"
                />

            <SettingsTabsNav :tabs="tabs" active="brand" />

            <BrandTab
                :workspace="workspace"
                :available-fonts="availableFonts"
                :available-image-styles="availableImageStyles"
                :available-voice-traits="availableVoiceTraits"
                :available-content-languages="availableContentLanguages"
            />
        </div>
    </AppLayout>
</template>
