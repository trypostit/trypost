<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import BrandTab from '@/components/settings/BrandTab.vue';
import SettingsTabsNav from '@/components/settings/SettingsTabsNav.vue';
import { useWorkspaceSettingsTabs } from '@/composables/useWorkspaceSettingsTabs';
import AppLayout from '@/layouts/AppLayout.vue';
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

const tabs = useWorkspaceSettingsTabs();
</script>

<template>
    <Head :title="$t('settings.workspace.tabs.brand')" />

    <AppLayout :title="$t('settings.hub.title')">
        <div class="mx-auto max-w-4xl space-y-8 px-6 py-8">
            <p class="text-sm text-muted-foreground">
                {{ $t('settings.hub.description') }}
            </p>

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
