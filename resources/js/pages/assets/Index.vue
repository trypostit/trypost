<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import GalleryBrowser from '@/components/assets/GalleryBrowser.vue';
import HeaderTitle from '@/components/HeaderTitle.vue';
import AppLayout from '@/layouts/AppLayout.vue';
</script>

<template>
    <Head :title="$t('assets.title')" />

    <AppLayout>
        <template #header>
            <HeaderTitle :title="$t('assets.title')" />
        </template>

        <div class="flex h-full flex-1 flex-col gap-6 px-6 py-8">
            <GalleryBrowser mode="standalone" />
        </div>
    </AppLayout>
</template>
