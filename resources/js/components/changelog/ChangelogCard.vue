<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconArrowRight } from '@tabler/icons-vue';
import { computed } from 'vue';

import ChangelogTagBadge from '@/components/changelog/ChangelogTagBadge.vue';
import { useChangelogDialog } from '@/composables/changelog/useChangelogDialog';

const page = usePage();
const { openEntry } = useChangelogDialog();

const entry = computed(
    () => page.props.changelog?.latestUnseen ?? null,
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
            class="flex w-full flex-col gap-2.5 rounded-xl border border-border bg-card p-2.5 text-start shadow-xs transition-control hover:bg-secondary focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-hidden"
            :aria-label="entry.title"
            data-testid="changelog-card"
            @click="openLatestEntry"
        >
            <img
                :src="entry.cover_url"
                alt=""
                class="aspect-[8/5] w-full rounded-lg object-cover"
                data-testid="changelog-card-cover"
            />
            <span
                class="text-sm leading-5 font-medium text-foreground"
                data-testid="changelog-card-title"
            >
                <ChangelogTagBadge
                    v-for="tag in entry.tags"
                    :key="tag.value"
                    :tag="tag"
                    class="me-1 align-[2px]"
                />
                {{ entry.title }}
                <IconArrowRight class="inline size-4 align-[-3px]" />
            </span>
        </button>
    </div>
</template>
