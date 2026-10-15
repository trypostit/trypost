<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
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
        class="px-3 pb-3 perspective-midrange group-data-[collapsible=icon]:hidden"
    >
        <button
            type="button"
            class="group/changelog flex w-full flex-col gap-2 rounded-xl border border-border-strong bg-card p-2 text-start origin-top transition duration-300 ease-out hover:bg-accent hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring motion-safe:hover:-rotate-x-12 motion-safe:active:rotate-x-0 motion-safe:active:shadow-none"
            :aria-label="entry.title"
            data-testid="changelog-card"
            @click="openLatestEntry"
        >
            <span
                class="block overflow-hidden rounded-lg border border-border"
            >
                <img
                    :src="entry.cover_url"
                    alt=""
                    class="aspect-video w-full object-cover transition-transform duration-500 ease-out motion-safe:group-hover/changelog:scale-[1.08] motion-safe:group-hover/changelog:-rotate-1"
                    data-testid="changelog-card-cover"
                />
            </span>
            <span
                class="px-1 pb-0.5 text-sm leading-5 font-medium text-foreground"
                data-testid="changelog-card-title"
            >
                {{ entry.title }}
            </span>
        </button>
    </div>
</template>
