<script setup lang="ts">
import { useId } from 'reka-ui';
import { computed, ref, toRefs, watch, type HTMLAttributes } from 'vue';

import { sidebarResizing } from '@/components/ui/sidebar/utils';
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

const root = ref<HTMLElement | null>(null);
const frozenWidth = ref<number | null>(null);

watch(sidebarResizing, (resizing) => {
    frozenWidth.value = resizing ? (root.value?.offsetWidth ?? null) : null;
});

provideChartContext({ id, config });
</script>

<template>
    <div
        ref="root"
        data-slot="chart"
        :data-chart="chartId"
        :class="
            cn(
                'flex h-full w-full flex-col justify-center text-xs [&_[data-vis-xy-container]]:h-full [&_[data-vis-xy-container]]:w-full',
                props.class,
            )
        "
        :style="{
            ...(frozenWidth === null
                ? {}
                : { width: `${frozenWidth}px`, maxWidth: 'none', flexShrink: '0' }),
            '--vis-tooltip-padding': '0px',
            '--vis-tooltip-background-color': 'transparent',
            '--vis-tooltip-border-color': 'transparent',
            '--vis-tooltip-text-color': 'transparent',
            '--vis-tooltip-shadow-color': 'transparent',
            '--vis-crosshair-circle-stroke-color': 'var(--card)',
            '--vis-crosshair-circle-stroke-width': '2px',
            '--vis-crosshair-circle-stroke-opacity': '1',
            '--vis-crosshair-line-stroke-color': 'var(--border-strong)',
            '--vis-crosshair-line-stroke-width': cursor ? '1px' : '0px',
            '--vis-crosshair-line-stroke-dasharray': '4 4',
            '--vis-crosshair-line-stroke-opacity': '1',
            '--vis-font-family': 'var(--font-sans)',
            '--vis-axis-font-family': 'var(--font-sans)',
            '--vis-axis-tick-label-color': 'var(--muted-foreground)',
            '--vis-axis-tick-label-font-size': '11px',
            '--vis-axis-label-color': 'var(--muted-foreground)',
            '--vis-axis-label-font-size': '11px',
            '--vis-axis-label-weight': '500',
            '--vis-axis-grid-color': 'var(--border)',
            '--vis-axis-grid-line-dasharray': '3 3',
            '--vis-axis-grid-line-width': '1px',
            '--vis-axis-grid-opacity': '1',
            '--vis-axis-domain-color': 'var(--border-strong)',
            '--vis-stacked-bar-hover-stroke-width': '0px',
            ...colorStyles,
        }"
    >
        <slot />
    </div>
</template>
