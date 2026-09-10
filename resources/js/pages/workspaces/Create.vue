<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

import BrandForm from '@/components/BrandForm.vue';
import { Button } from '@/components/ui/button';
import WorkspaceUpgradeDialog from '@/components/workspaces/WorkspaceUpgradeDialog.vue';
import { useWorkspaceLimit } from '@/composables/useWorkspaceLimit';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { store as storeWorkspace } from '@/routes/app/workspaces';
import type { ContentLanguageOption, SharedData } from '@/types';

defineProps<{
    availableFonts: string[];
    availableImageStyles: string[];
    availableVoiceTraits: Record<string, string[]>;
    availableContentLanguages: ContentLanguageOption[];
}>();

const page = usePage<SharedData>();
const { atWorkspaceLimit } = useWorkspaceLimit(
    () => page.props.auth.workspaces.length,
);
const upgradeDialogOpen = ref(false);

const form = useForm({
    name: '',
    brand_website: '',
    brand_description: '',
    brand_voice_traits: [] as string[],
    brand_color: null as string | null,
    background_color: null as string | null,
    text_color: null as string | null,
    brand_font: 'Inter',
    image_style: 'cinematic',
    content_language: 'en',
    logo_url: '' as string | null,
});

const submit = (): void => {
    if (atWorkspaceLimit.value) {
        upgradeDialogOpen.value = true;

        return;
    }

    form.post(storeWorkspace.url());
};
</script>

<template>
    <Head :title="$t('workspaces.create.page_title')" />

    <AuthLayout
        :title="$t('workspaces.create.title')"
        :description="$t('workspaces.create.description')"
    >
        <form class="flex flex-col space-y-6" @submit.prevent="submit">
            <BrandForm
                :fields="form"
                :errors="form.errors"
                :available-fonts="availableFonts"
                :available-image-styles="availableImageStyles"
                :available-voice-traits="availableVoiceTraits"
                :available-content-languages="availableContentLanguages"
                :autofill="true"
                :show-name="true"
            />

            <Button
                type="submit"
                class="w-full"
                data-testid="workspaces-create-submit"
                :disabled="form.processing"
            >
                {{ $t('workspaces.create.submit') }}
            </Button>
        </form>

        <WorkspaceUpgradeDialog v-model:open="upgradeDialogOpen" />
    </AuthLayout>
</template>
