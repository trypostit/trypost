<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

import BrandForm from '@/components/BrandForm.vue';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { store as storeWorkspace } from '@/routes/app/workspaces';
import type { ContentLanguageOption } from '@/types';

defineProps<{
    availableFonts: string[];
    availableImageStyles: string[];
    availableVoiceTraits: Record<string, string[]>;
    availableContentLanguages: ContentLanguageOption[];
}>();

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

const submit = () => {
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

            <Button type="submit" class="w-full" :disabled="form.processing">
                {{ $t('workspaces.create.submit') }}
            </Button>
        </form>
    </AuthLayout>
</template>
