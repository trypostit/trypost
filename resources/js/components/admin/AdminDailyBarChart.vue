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
import { DAILY_CHART_PADDING, useAdminDayAxis } from '@/composables/admin/useAdminDayAxis';
import { useChartAppContext } from '@/composables/analytics/useChartAppContext';
import { countIn } from '@/lib/adminFormat';
import { filled } from '@/lib/helpers';
import { formatFullNumber, formatMoney } from '@/lib/utils';

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
    [key: string]: number;
};

const props = defineProps<{
    /** Calendar days, `YYYY-MM-DD`, oldest first. */
    days: string[];
    series: AdminChartSeries[];
    testId?: string;
    /** The values are cents of a US dollar: the axis and the tooltip total show them as money. */
    currency?: boolean;
    /** The tooltip ends with the day's total across the series. */
    showTotal?: boolean;
}>();

const { ticks, formatDay } = useAdminDayAxis(() => props.days);

const formatValueTick = numericTick((tick, index, ticks): string => {
    if (props.currency) {
        return formatMoney(tick);
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
        return formatMoney(total);
    }

    return formatFullNumber(total);
};

/** The day's total across the series, as money when the chart is a cost. */
const dayTotal = numericTick((tick): ChartTooltipDetail | null => {
    if (!wantsTotal.value) {
        return null;
    }

    const point = chartData.value[Math.round(tick)];

    if (filled(point)) {
        const total = props.series.reduce((sum, series) => sum + countIn(point, series.key), 0);

        return { label: 'Total', value: formatTotal(total) };
    }

    return null;
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
    props.days.map((_, index) => {
        const point: ChartPoint = { index };

        props.series.forEach((series) => {
            point[series.key] = countIn(series.values, index);
        });

        return point;
    }),
);

const xAccessor = (point: ChartPoint): number => point.index;
const yAccessors = computed(() =>
    props.series.map(
        (series) =>
            (point: ChartPoint): number =>
                countIn(point, series.key),
    ),
);
const barColors = computed(() => props.series.map((series) => series.color));

const seriesByKey = computed(() => new Map(props.series.map((series) => [series.key, series])));

/** The day under the pointer, read by the series' tooltip details. */
const hoveredIndex = ref(0);

const seriesValue = (key: string, value: number): string => {
    const format = seriesByKey.value.get(key)?.format;

    if (filled(format)) {
        return format(hoveredIndex.value, value);
    }

    return formatFullNumber(value);
};

const seriesDetails = (key: string, value: number): ChartTooltipDetail[] => {
    const details = seriesByKey.value.get(key)?.details;

    if (filled(details)) {
        return details(hoveredIndex.value, value);
    }

    return [];
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
            :padding="DAILY_CHART_PADDING"
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
