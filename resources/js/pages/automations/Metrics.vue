<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import AutomationDetailLayout from '@/components/automations/AutomationDetailLayout.vue';
import AutomationRunsChart from '@/components/automations/AutomationRunsChart.vue';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import date from '@/date';
import dayjs from '@/dayjs';
import type { Automation } from '@/types/automation/automation';

type Metrics = {
    totals: {
        runs: number;
        completed: number;
        failed: number;
        in_progress: number;
        success_rate: number | null;
        avg_duration_ms: number | null;
        posts_created: number;
    };
    timeseries: { date: string; started: number; completed: number; failed: number }[];
    platforms: { platform: string; count: number }[];
};

const props = defineProps<{
    automation: Automation;
    metrics: Metrics;
    filters: { start: string; end: string };
}>();

const dateRange = ref({
    start: dayjs(props.filters.start).toDate(),
    end: dayjs(props.filters.end).toDate(),
});

watch(
    dateRange,
    (range) => {
        router.reload({
            data: {
                start: dayjs(range.start).format('YYYY-MM-DD'),
                end: dayjs(range.end).format('YYYY-MM-DD'),
            },
            only: ['metrics', 'filters'],
        });
    },
    { deep: true },
);

const cards = computed(() => [
    { key: 'runs', label: 'automations.metrics.cards.runs', value: String(props.metrics.totals.runs) },
    { key: 'completed', label: 'automations.metrics.cards.completed', value: String(props.metrics.totals.completed) },
    { key: 'failed', label: 'automations.metrics.cards.failed', value: String(props.metrics.totals.failed) },
    { key: 'in_progress', label: 'automations.metrics.cards.in_progress', value: String(props.metrics.totals.in_progress) },
    { key: 'success_rate', label: 'automations.metrics.cards.success_rate', value: props.metrics.totals.success_rate === null ? '—' : `${props.metrics.totals.success_rate}%` },
    { key: 'avg_duration', label: 'automations.metrics.cards.avg_duration', value: date.formatDurationMs(props.metrics.totals.avg_duration_ms) },
    { key: 'posts_created', label: 'automations.metrics.cards.posts_created', value: String(props.metrics.totals.posts_created) },
]);

const legend = [
    { key: 'started', color: '#6366f1', label: 'automations.metrics.legend.started' },
    { key: 'completed', color: '#22c55e', label: 'automations.metrics.legend.completed' },
    { key: 'failed', color: '#ef4444', label: 'automations.metrics.legend.failed' },
];

const maxPlatform = computed(() => Math.max(1, ...props.metrics.platforms.map((p) => p.count)));

const platformLabel = (platform: string): string => platform.charAt(0).toUpperCase() + platform.slice(1);
</script>

<template>
    <AutomationDetailLayout :automation="automation" current="metrics">
        <div class="space-y-6 p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-sm font-semibold text-foreground/70">{{ $t('automations.metrics.overview') }}</h2>
                <DateRangePicker v-model="dateRange" dusk="metrics-range" />
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-7">
                <div v-for="card in cards" :key="card.key" class="rounded-xl border-2 border-foreground/10 bg-card p-4">
                    <p class="text-xs text-foreground/55">{{ $t(card.label) }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ card.value }}</p>
                </div>
            </div>

            <div class="rounded-xl border-2 border-foreground/10 bg-card p-4">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold">{{ $t('automations.metrics.runs_over_time') }}</h3>
                    <div class="flex flex-wrap items-center gap-4">
                        <div v-for="item in legend" :key="item.key" class="flex items-center gap-1.5">
                            <span class="size-2.5 rounded-full" :style="{ backgroundColor: item.color }"></span>
                            <span class="text-xs text-foreground/60">{{ $t(item.label) }}</span>
                        </div>
                    </div>
                </div>
                <AutomationRunsChart :data="metrics.timeseries" />
            </div>

            <div class="rounded-xl border-2 border-foreground/10 bg-card p-4">
                <h3 class="mb-3 text-sm font-semibold">{{ $t('automations.metrics.posts_by_platform') }}</h3>
                <div v-if="metrics.platforms.length === 0" class="py-6 text-center text-sm text-foreground/50">
                    {{ $t('automations.metrics.no_posts') }}
                </div>
                <ul v-else class="space-y-2">
                    <li v-for="item in metrics.platforms" :key="item.platform" class="flex items-center gap-3">
                        <span class="w-24 shrink-0 text-sm text-foreground/70">{{ platformLabel(item.platform) }}</span>
                        <div class="h-5 flex-1 overflow-hidden rounded bg-muted">
                            <div class="h-full rounded bg-primary/70" :style="{ width: `${(item.count / maxPlatform) * 100}%` }"></div>
                        </div>
                        <span class="w-8 shrink-0 text-right text-sm tabular-nums text-foreground/70">{{ item.count }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </AutomationDetailLayout>
</template>
