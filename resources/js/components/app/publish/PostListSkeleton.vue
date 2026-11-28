<script setup lang="ts">
import { computed } from 'vue';

import { Skeleton } from '@/components/ui/skeleton';
import type { PublishTab } from '@/types/app/publish';

type SkeletonRow = 'card' | 'media' | 'slot';

const props = withDefaults(
    defineProps<{
        tab: PublishTab;
        variant?: 'list' | 'timeline';
    }>(),
    { variant: 'list' },
);

const LIST_DAYS: SkeletonRow[][] = [
    ['media', 'card'],
    ['card', 'media', 'card'],
];

const TIMELINE_DAYS: SkeletonRow[][] = [
    ['media', 'slot', 'slot'],
    ['slot', 'card', 'slot'],
];

const days = computed(() =>
    props.variant === 'timeline' ? TIMELINE_DAYS : LIST_DAYS,
);
</script>

<template>
    <div
        class="min-h-0 min-w-0 flex-1 overflow-hidden pb-px"
        role="status"
        aria-busy="true"
        :aria-label="$t('common.loading')"
        :data-testid="`publish-list-skeleton-${tab}`"
        :data-variant="variant"
    >
        <div
            aria-hidden="true"
            class="mx-auto flex w-full max-w-[900px] flex-col gap-10 px-4 pt-6 pb-12 md:px-12"
        >
            <section
                v-for="(rows, day) in days"
                :key="day"
                class="flex flex-col gap-6"
            >
                <Skeleton class="h-5 w-48" />
                <template v-for="(row, index) in rows" :key="index">
                    <div
                        v-if="row === 'slot'"
                        class="grid grid-cols-[4.5rem_minmax(0,1fr)] items-center gap-x-4 md:grid-cols-[71px_minmax(0,1fr)] md:gap-x-8"
                        data-testid="publish-list-skeleton-slot"
                    >
                        <Skeleton class="h-5 w-14" />
                        <div
                            class="flex h-12 items-center gap-2 rounded-xl border border-border-strong bg-card px-2"
                        >
                            <Skeleton class="size-5 rounded-md" />
                            <Skeleton class="h-4 w-24" />
                        </div>
                    </div>
                    <div
                        v-else
                        class="grid grid-cols-[minmax(0,1fr)_2.5rem] gap-x-3 gap-y-2 md:grid-cols-[71px_minmax(0,1fr)] md:gap-x-8"
                        data-testid="publish-list-skeleton-card"
                    >
                        <div
                            class="col-span-2 flex items-center gap-2 md:col-span-1 md:flex-col md:items-start"
                        >
                            <Skeleton class="h-5 w-14" />
                        </div>
                        <div
                            class="min-w-0 overflow-hidden rounded-xl border border-border-strong bg-card"
                        >
                            <div class="flex gap-4 p-4 md:gap-6">
                                <div class="flex min-w-0 flex-1 flex-col gap-4">
                                    <div class="flex items-center gap-3">
                                        <Skeleton class="size-8 rounded-lg" />
                                        <Skeleton class="h-4 w-32" />
                                    </div>
                                    <div class="flex flex-col gap-2">
                                        <Skeleton class="h-3.5 w-full" />
                                        <Skeleton class="h-3.5 w-11/12" />
                                        <Skeleton class="h-3.5 w-2/3" />
                                    </div>
                                </div>
                                <Skeleton
                                    v-if="row === 'media'"
                                    class="aspect-square w-24 shrink-0 md:w-[180px]"
                                />
                            </div>
                            <div
                                v-if="tab === 'sent'"
                                class="flex gap-8 border-t border-border-strong px-4 py-3"
                                data-testid="publish-list-skeleton-metrics"
                            >
                                <div
                                    v-for="metric in 4"
                                    :key="metric"
                                    class="flex flex-col gap-1.5"
                                >
                                    <Skeleton class="h-3 w-14" />
                                    <Skeleton class="h-4 w-8" />
                                </div>
                            </div>
                            <div
                                class="m-2 flex min-h-14 items-center justify-between gap-4 rounded-xl bg-muted p-3"
                            >
                                <Skeleton class="h-4 w-40 bg-secondary" />
                                <Skeleton class="h-8 w-24 bg-secondary" />
                            </div>
                        </div>
                    </div>
                </template>
            </section>
        </div>
    </div>
</template>
