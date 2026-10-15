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
        class="px-3 pb-3 group-data-[collapsible=icon]:hidden"
    >
        <button
            type="button"
            class="flex w-full flex-col gap-2 rounded-xl border border-border-strong bg-card p-2 text-start transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
            :aria-label="entry.title"
            data-testid="changelog-card"
            @click="openLatestEntry"
        >
            <img
                :src="entry.cover_url"
                alt=""
                class="aspect-video w-full rounded-lg border border-border object-cover"
                data-testid="changelog-card-cover"
            />
            <span
                class="px-1 pb-0.5 text-sm leading-5 font-medium text-foreground"
                data-testid="changelog-card-title"
            >
                {{ entry.title }}
            </span>
        </button>
    </div>
</template>
