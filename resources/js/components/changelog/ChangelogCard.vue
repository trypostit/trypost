<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconArrowRight } from '@tabler/icons-vue';
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
            class="group/changelog flex w-full flex-col rounded-xl border border-border-strong bg-card p-3 text-start transition-[background-color,box-shadow] duration-200 ease-out hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
            :aria-label="entry.title"
            data-testid="changelog-card"
            @click="openLatestEntry"
        >
            <span
                class="line-clamp-2 text-[13px] leading-5 font-medium text-foreground"
                data-testid="changelog-card-title"
            >
                {{ entry.title }}
            </span>
            <span
                class="mt-3 block aspect-video overflow-hidden rounded-lg bg-muted"
            >
                <img
                    v-if="entry.cover_url"
                    :src="entry.cover_url"
                    alt=""
                    class="size-full object-cover"
                    draggable="false"
                    data-testid="changelog-card-cover"
                />
            </span>
            <span
                class="grid grid-rows-[0fr] opacity-0 transition-[grid-template-rows,opacity] duration-200 ease-out group-hover/changelog:grid-rows-[1fr] group-hover/changelog:opacity-100 group-focus-visible/changelog:grid-rows-[1fr] group-focus-visible/changelog:opacity-100"
                aria-hidden="true"
            >
                <span class="overflow-hidden">
                    <span
                        class="flex items-center gap-1 pt-3 text-xs font-medium text-foreground"
                    >
                        Read more
                        <IconArrowRight
                            class="size-3.5 transition-transform duration-200 ease-out motion-safe:group-hover/changelog:translate-x-0.5"
                        />
                    </span>
                </span>
            </span>
        </button>
    </div>
</template>
