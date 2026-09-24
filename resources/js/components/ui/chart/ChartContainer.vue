<script setup lang="ts">
import { useId } from 'reka-ui';
import { computed, toRefs, type HTMLAttributes } from 'vue';

import { cn } from '@/lib/utils';

import { provideChartContext, type ChartConfig } from '.';

const props = defineProps<{
    class?: HTMLAttributes['class'];
    config: ChartConfig;
    cursor?: boolean;
}>();

const { config } = toRefs(props);
const id = useId().replace(/:/g, '');
const chartId = computed(() => `chart-${id}`);
const colorStyles = computed(() =>
    Object.fromEntries(
        Object.entries(config.value).map(([key, item]) => [
            `--color-${key}`,
            item.color,
        ]),
    ),
);

provideChartContext({ id, config });
</script>

<template>
    <div
        data-slot="chart"
        :data-chart="chartId"
        :class="
            cn(
                'flex h-full w-full flex-col justify-center text-xs [&_[data-vis-xy-container]]:h-full [&_[data-vis-xy-container]]:w-full',
                props.class,
            )
        "
        :style="{
            '--vis-tooltip-padding': '0px',
            '--vis-tooltip-background-color': 'transparent',
            '--vis-tooltip-border-color': 'transparent',
            '--vis-tooltip-text-color': 'transparent',
            '--vis-tooltip-shadow-color': 'transparent',
            '--vis-crosshair-circle-stroke-color': 'transparent',
            '--vis-crosshair-line-stroke-width': cursor ? '1px' : '0px',
            '--vis-font-family': 'var(--font-sans)',
            '--vis-axis-tick-label-color': 'var(--muted-foreground)',
            '--vis-axis-tick-label-font-size': '11px',
            '--vis-axis-grid-line-color':
                'color-mix(in srgb, var(--foreground) 12%, transparent)',
            ...colorStyles,
        }"
    >
        <slot />
    </div>
</template>
