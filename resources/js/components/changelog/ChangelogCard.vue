<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconArrowUpRight } from '@tabler/icons-vue';
import { computed } from 'vue';

import { useChangelogDialog } from '@/composables/changelog/useChangelogDialog';

const page = usePage();
const { openEntry } = useChangelogDialog();

const entry = computed(
    () => page.props.changelog?.latest ?? null,
);

const openLatestEntry = (): void => {
    if (entry.value) {
        openEntry(entry.value);
    }
};
</script>

<template>
    <div
        v-if="entry"
        class="px-3 pb-3 group-data-[collapsible=icon]:hidden"
    >
        <button
            type="button"
            class="group/changelog flex w-full flex-col gap-2.5 rounded-xl border border-border bg-card p-2.5 text-start shadow-xs transition duration-200 ease-out hover:border-border-strong hover:shadow-md focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-hidden motion-safe:hover:-translate-y-0.5 motion-safe:active:translate-y-0 motion-safe:active:scale-[0.99]"
            :aria-label="entry.title"
            data-testid="changelog-card"
            @click="openLatestEntry"
        >
            <span class="relative block overflow-hidden rounded-lg">
                <img
                    :src="entry.cover_url"
                    alt=""
                    class="aspect-video w-full object-cover transition-transform duration-300 ease-out motion-safe:group-hover/changelog:scale-105"
                    data-testid="changelog-card-cover"
                />
                <span
                    class="absolute end-2 top-2 flex size-7 items-center justify-center rounded-full bg-background/90 text-foreground opacity-0 shadow-sm transition duration-200 ease-out group-hover/changelog:opacity-100 group-focus-visible/changelog:opacity-100 motion-safe:translate-y-1 motion-safe:-translate-x-1 motion-safe:group-hover/changelog:translate-0 motion-safe:group-focus-visible/changelog:translate-0"
                    aria-hidden="true"
                    data-testid="changelog-card-arrow"
                >
                    <IconArrowUpRight class="size-4" />
                </span>
            </span>
            <span
                class="text-sm leading-5 font-medium text-foreground"
                data-testid="changelog-card-title"
            >
                {{ entry.title }}
            </span>
        </button>
    </div>
</template>
