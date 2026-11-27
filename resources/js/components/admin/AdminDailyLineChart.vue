<script setup lang="ts">
import { VisArea, VisAxis, VisLine, VisXYContainer } from '@unovis/vue';
import { computed, useId } from 'vue';

import { formatCountTick } from '@/components/analytics/workspace/charts/socialAccountChart';
import {
    type ChartConfig,
    ChartContainer,
    ChartCrosshair,
    ChartTooltip,
    ChartTooltipContent,
    componentToString,
    numericTick,
} from '@/components/ui/chart';
import { useChartAppContext } from '@/composables/analytics/useChartAppContext';
import date from '@/date';

type ChartPoint = {
    index: number;
    label: string;
    value: number;
};

/** One label every few days, so a long range stays readable. */
const MAX_TICKS = 8;

const props = defineProps<{
    /** Calendar days, `YYYY-MM-DD`, oldest first. */
    days: string[];
    /** One value per day, in the order of `days`. */
    values: number[];
    label: string;
    color: string;
    testId?: string;
    /** The values are percentages: the axis and the tooltip show them so. */
    percent?: boolean;
}>();

const formatValue = (value: number): string => {
    if (props.percent) {
        return `${value.toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;
    }

    return value.toLocaleString();
};

const formatValueTick = numericTick((tick, index, ticks): string => {
    if (props.percent) {
        return formatValue(tick);
    }

    return formatCountTick(tick, index, ticks);
});

const chartConfig = computed(
    (): ChartConfig => ({ value: { label: props.label, color: props.color } }),
);

const chartData = computed<ChartPoint[]>(() =>
    props.days.map((day, index) => ({
        index,
        label: date.formatMonthDay(day),
        value: props.values[index] ?? 0,
    })),
);

/** The axis starts near the lowest value, so a change of a few channels still shows. */
const yDomain = computed((): [number, number] => {
    const lowest = Math.min(...props.values);
    const highest = Math.max(...props.values);
    const margin = Math.max(1, Math.round((highest - lowest) * 0.1));

    if (lowest < 0) {
        return [lowest - margin, highest + margin];
    }

    return [Math.max(0, lowest - margin), highest + margin];
});

/** The fill under the line fades from the line's color to nothing, like a stock chart. */
const gradientId = `admin-line-fill-${useId()}`;
const svgDefs = computed(
    (): string => `<linearGradient id="${gradientId}" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="${props.color}" stop-opacity="0.32" />
        <stop offset="100%" stop-color="${props.color}" stop-opacity="0" />
    </linearGradient>`,
);
const areaFill = `url(#${gradientId})`;

/** The fill stops at the bottom of the axis, not at zero: unovis stacks the area's height on its baseline. */
const areaBaseline = (): number => yDomain.value[0];
const areaHeight = (point: ChartPoint): number => point.value - yDomain.value[0];

const xAccessor = (point: ChartPoint): number => point.index;
const yAccessor = (point: ChartPoint): number => point.value;

const formatDay = numericTick(
    (tick): string => chartData.value[Math.round(tick)]?.label ?? '',
);

const ticks = computed((): number[] => {
    const step = Math.max(1, Math.ceil(props.days.length / MAX_TICKS));

    return props.days
        .map((_, index) => index)
        .filter((index) => index % step === 0);
});

const appContext = useChartAppContext();
const tooltipTemplate = computed(() =>
    componentToString(
        chartConfig.value,
        ChartTooltipContent,
        {
            labelFormatter: formatDay,
            valueFormatter: (_key: string, value: number): string => formatValue(value),
        },
        appContext,
    ),
);
</script>

<template>
    <ChartContainer :config="chartConfig" class="h-72 w-full sm:h-80" :data-testid="testId">
        <VisXYContainer
            :data="chartData"
            :y-domain="yDomain"
            :svg-defs="svgDefs"
            :padding="{ top: 14, right: 8, bottom: 8, left: 0 }"
        >
            <VisArea
                :x="xAccessor"
                :y="areaHeight"
                :baseline="areaBaseline"
                :color="areaFill"
                :curve-type="'monotoneX'"
            />
            <VisLine
                :x="xAccessor"
                :y="yAccessor"
                :color="color"
                :line-width="2"
                :curve-type="'monotoneX'"
            />
            <VisAxis
                type="x"
                :tick-format="formatDay"
                :tick-values="ticks"
                :grid-line="false"
                :domain-line="false"
                :tick-line="false"
            />
            <VisAxis
                type="y"
                :tick-format="formatValueTick"
                :num-ticks="5"
                :grid-line="true"
                :domain-line="false"
                :tick-line="false"
            />
            <ChartCrosshair
                :template="tooltipTemplate"
                color="var(--foreground)"
                :circle-radius="5"
                stroke-color="var(--card)"
                :stroke-width="2"
            />
            <ChartTooltip />
        </VisXYContainer>
    </ChartContainer>
</template>
