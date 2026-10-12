<script setup lang="ts">
import { IconExternalLink } from '@tabler/icons-vue';

import SettingsSidebar from '@/components/settings/SettingsSidebar.vue';
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';

type Props = {
    fullWidth?: boolean;
    centered?: boolean;
    title?: string;
    description?: string;
    /** Docs page linked as "Learn more" at the end of the description. */
    learnMoreUrl?: string;
};

withDefaults(defineProps<Props>(), {
    fullWidth: false,
    centered: false,
    title: undefined,
    description: undefined,
    learnMoreUrl: undefined,
});
</script>

<template>
    <AppSidebarLayout :full-width="fullWidth || centered">
        <template #sidebar>
            <SettingsSidebar />
        </template>
        <template v-if="$slots['header']" #header>
            <slot name="header" />
        </template>
        <template v-if="$slots['header'] && $slots['header-actions']" #header-actions>
            <slot name="header-actions" />
        </template>

        <div
            v-if="centered"
            class="flex flex-1 flex-col items-center px-4 py-16"
            data-testid="settings-centered"
        >
            <div class="my-auto w-full max-w-[664px]">
                <slot />
            </div>
        </div>

        <div
            v-else-if="title"
            class="mx-auto flex w-full max-w-[664px] flex-col gap-8 px-4 pt-2 pb-16 md:px-8 md:pt-10"
            data-testid="settings-page"
        >
            <header class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h1
                        class="font-heading text-xl leading-tight font-medium text-foreground"
                        data-testid="header-title"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="mt-1 text-sm text-muted-foreground"
                        data-testid="settings-page-description"
                    >
                        {{ description }}
                        <a
                            v-if="learnMoreUrl"
                            :href="learnMoreUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 text-primary-text underline underline-offset-2 hover:text-primary-text-hover"
                            data-testid="settings-page-learn-more"
                        >
                            {{ $t('common.learn_more') }}
                            <IconExternalLink class="size-3.5" />
                        </a>
                    </p>
                </div>
                <div
                    v-if="$slots['actions']"
                    class="flex shrink-0 items-center gap-2"
                >
                    <slot name="actions" />
                </div>
            </header>

            <slot />
        </div>

        <slot v-else />
    </AppSidebarLayout>
</template>
