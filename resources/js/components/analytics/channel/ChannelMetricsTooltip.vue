<script setup lang="ts">
import { computed } from 'vue';

import {
    isPercentMetric,
    type ChartMode,
    type SeriesSpec,
} from '@/lib/channelMetrics';
import { formatFullNumber, formatPercent } from '@/lib/utils';
import type { MetricSeriesPoint, SeriesMetric } from '@/types/analytics';

const props = withDefaults(
    defineProps<{
        payload?: Record<string, unknown>;
        config?: Record<string, unknown>;
        x?: number | Date;
        specs: SeriesSpec[];
        labels: Partial<Record<SeriesMetric, string>>;
        mode: ChartMode;
        dateLabel: (point: MetricSeriesPoint | undefined) => string;
    }>(),
    { payload: () => ({}) },
);

const periods = computed(() => {
    const current = props.payload.current as MetricSeriesPoint | undefined;
    const previous = props.payload.previous as MetricSeriesPoint | undefined;
    const shown: { key: string; point: MetricSeriesPoint | undefined; dashed: boolean }[] = [];

    if (props.mode !== 'previous') {
        shown.push({ key: 'current', point: current, dashed: false });
    }

    if (props.mode !== 'current') {
        shown.push({ key: 'previous', point: previous, dashed: true });
    }

    return shown;
});

const display = (
    value: number | null | undefined,
    metric: SeriesMetric,
): string => {
    if (isPercentMetric(metric)) {
        return formatPercent(value);
    }

    return formatFullNumber(value);
};
</script>

<template>
    <div
        class="min-w-52 overflow-hidden rounded-xl bg-popover text-xs text-popover-foreground shadow-md ring-1 ring-foreground/5 dark:ring-border"
        data-testid="insights-metrics-tooltip"
    >
        <div
            v-for="period in periods"
            :key="period.key"
            class="not-first:border-t not-first:border-border"
            :data-testid="`insights-metrics-tooltip-${period.key}`"
        >
            <p
                class="px-3 pt-2.5 font-medium"
                :class="period.dashed ? 'text-muted-foreground' : 'text-foreground'"
            >
                {{ dateLabel(period.point) }}
            </p>
            <div class="grid gap-1.5 px-3 pt-2 pb-2.5">
                <div
                    v-for="spec in specs"
                    :key="spec.metric"
                    class="flex items-center justify-between gap-6"
                >
                    <span
                        class="inline-flex min-w-0 items-center gap-2 text-muted-foreground"
                    >
                        <span
                            class="size-2.5 shrink-0 rounded-[3px]"
                            :style="
                                period.dashed
                                    ? { border: `1.5px dashed ${spec.color}` }
                                    : { backgroundColor: spec.color }
                            "
                        />
                        <span class="truncate">{{ labels[spec.metric] }}</span>
                    </span>
                    <span class="font-medium text-foreground tabular-nums">{{
                        display(period.point?.values[spec.metric], spec.metric)
                    }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
