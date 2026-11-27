<script setup lang="ts">
import {
    VisAxis,
    VisStackedBar,
    VisStackedBarSelectors,
    VisXYContainer,
} from '@unovis/vue';
import { computed, ref } from 'vue';

import { formatCountTick } from '@/components/analytics/workspace/charts/socialAccountChart';
import {
    type ChartConfig,
    ChartContainer,
    ChartTooltip,
    type ChartTooltipDetail,
    ChartTooltipContent,
    componentToString,
    numericTick,
} from '@/components/ui/chart';
import { useChartAppContext } from '@/composables/analytics/useChartAppContext';
import date from '@/date';

/** One stacked series of the chart: a value per day, in the order of `days`. */
export interface AdminChartSeries {
    key: string;
    label: string;
    color: string;
    values: number[];
    /** How this series' value reads in the tooltip, for the day at `index`. */
    format?: (index: number, value: number) => string;
    /** Labelled numbers listed under this series in the tooltip, for the day at `index`. */
    details?: (index: number, value: number) => ChartTooltipDetail[];
}

type ChartPoint = {
    index: number;
    label: string;
    [key: string]: string | number;
};

/** One label every few days, so a long range stays readable. */
const MAX_TICKS = 8;

const props = defineProps<{
    /** Calendar days, `YYYY-MM-DD`, oldest first. */
    days: string[];
    series: AdminChartSeries[];
    testId?: string;
    /** The values are US dollars: the axis and the tooltip total show them so. */
    currency?: boolean;
    /** The tooltip ends with the day's total across the series. */
    showTotal?: boolean;
}>();

const usd = (value: number): string =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value);

const formatValueTick = numericTick((tick, index, ticks): string => {
    if (props.currency) {
        return usd(tick);
    }

    return formatCountTick(tick, index, ticks);
});

const wantsTotal = computed((): boolean => {
    if (props.currency) {
        return true;
    }

    return props.showTotal;
});

const formatTotal = (total: number): string => {
    if (props.currency) {
        return usd(total);
    }

    return total.toLocaleString();
};

/** The day's total across the series, in dollars when the chart is a cost. */
const dayTotal = numericTick((tick): ChartTooltipDetail | null => {
    const point = chartData.value[Math.round(tick)];

    if (!wantsTotal.value || point === undefined) {
        return null;
    }

    const total = props.series.reduce((sum, series) => sum + Number(point[series.key] ?? 0), 0);

    return { label: 'Total', value: formatTotal(total) };
});

const chartConfig = computed(
    (): ChartConfig =>
        Object.fromEntries(
            props.series.map((series) => [
                series.key,
                { label: series.label, color: series.color },
            ]),
        ),
);

const chartData = computed<ChartPoint[]>(() =>
    props.days.map((day, index) => {
        const point: ChartPoint = { index, label: date.formatMonthDay(day) };

        props.series.forEach((series) => {
            point[series.key] = series.values[index] ?? 0;
        });

        return point;
    }),
);

const xAccessor = (point: ChartPoint): number => point.index;
const yAccessors = computed(() =>
    props.series.map(
        (series) =>
            (point: ChartPoint): number =>
                Number(point[series.key] ?? 0),
    ),
);
const barColors = computed(() => props.series.map((series) => series.color));

const formatDay = numericTick(
    (tick): string => chartData.value[Math.round(tick)]?.label ?? '',
);

const ticks = computed((): number[] => {
    const step = Math.max(1, Math.ceil(props.days.length / MAX_TICKS));

    return props.days
        .map((_, index) => index)
        .filter((index) => index % step === 0);
});

/** The day under the pointer, read by the series' tooltip details. */
const hoveredIndex = ref(0);

const seriesValue = (key: string, value: number): string => {
    const format = props.series.find((series) => series.key === key)?.format;

    if (format === undefined) {
        return value.toLocaleString();
    }

    return format(hoveredIndex.value, value);
};

const seriesDetails = (key: string, value: number): ChartTooltipDetail[] => {
    const details = props.series.find((series) => series.key === key)?.details;

    if (details === undefined) {
        return [];
    }

    return details(hoveredIndex.value, value);
};

const appContext = useChartAppContext();
const tooltipTemplate = computed(() =>
    componentToString(
        chartConfig.value,
        ChartTooltipContent,
        {
            labelFormatter: formatDay,
            totalFormatter: dayTotal,
            valueFormatter: seriesValue,
            detailFormatter: seriesDetails,
        },
        appContext,
    ),
);
const tooltipTriggers = computed(() => ({
    [VisStackedBarSelectors.bar]: (bar: {
        datum: ChartPoint;
    }): string | undefined => {
        hoveredIndex.value = bar.datum.index;

        return tooltipTemplate.value?.(bar.datum, bar.datum.index);
    },
}));
</script>

<template>
    <ChartContainer
        :config="chartConfig"
        cursor
        class="h-72 w-full sm:h-80 [--vis-stacked-bar-stroke-color:var(--card)] [--vis-stacked-bar-stroke-width:1.5px]"
        :data-testid="testId"
    >
        <VisXYContainer
            :data="chartData"
            :padding="{ top: 14, right: 8, bottom: 8, left: 0 }"
        >
            <VisStackedBar
                :x="xAccessor"
                :y="yAccessors"
                :color="barColors"
                :rounded-corners="4"
                :bar-padding="0.35"
                :bar-max-width="28"
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
            <ChartTooltip :triggers="tooltipTriggers" />
        </VisXYContainer>
    </ChartContainer>
</template>
