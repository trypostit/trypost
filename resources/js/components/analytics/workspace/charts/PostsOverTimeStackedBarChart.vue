<script setup lang="ts">
import {
    VisAxis,
    VisStackedBar,
    VisStackedBarSelectors,
    VisXYContainer,
} from '@unovis/vue';
import { computed } from 'vue';

import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    componentToString,
} from '@/components/ui/chart';
import date from '@/date';
import dayjs from '@/dayjs';

import { accountColor } from '@/lib/analyticsColors';
import type { PostAccount, PostBucket } from '@/types/analytics';

import {
    formatCountTick,
    socialAccountChartConfig,
} from './socialAccountChart';

type ChartPoint = {
    index: number;
    label: string;
    [key: string]: string | number;
};

const props = defineProps<{
    accounts: PostAccount[];
    buckets: PostBucket[];
    resolution: string;
    colors: Record<string, string>;
}>();

const bucketLabel = (bucket: PostBucket): string => {
    const start = dayjs(bucket.start);

    if (props.resolution === 'monthly')
        return date.formatMonthYear(start.month() + 1, start.year());
    if (bucket.start === bucket.end) return date.formatMonthDay(bucket.start);

    return `${date.formatMonthDay(bucket.start)} – ${date.formatMonthDay(bucket.end)}`;
};

const chartData = computed<ChartPoint[]>(() =>
    props.buckets.map((bucket, index) => {
        const point: ChartPoint = { index, label: bucketLabel(bucket) };

        props.accounts.forEach((account, accountIndex) => {
            point[`account_${accountIndex}`] =
                bucket.accounts[account.social_account_key] ?? 0;
        });

        return point;
    }),
);
const chartConfig = computed(() =>
    socialAccountChartConfig(props.accounts, props.colors),
);
const xAccessor = (point: ChartPoint): number => point.index;
const yAccessors = computed(() =>
    props.accounts.map(
        (_, index) =>
            (point: ChartPoint): number =>
                Number(point[`account_${index}`] ?? 0),
    ),
);
const barColors = computed(() =>
    props.accounts.map(
        (account, index) =>
            props.colors[account.social_account_key] ?? accountColor(index),
    ),
);
const formatBucket = (tick: number | Date): string => {
    const index = typeof tick === 'number' ? Math.round(tick) : 0;
    return chartData.value[index]?.label ?? '';
};
const ticks = computed(() => props.buckets.map((_, index) => index));
const tooltipTemplate = computed(() =>
    componentToString(chartConfig.value, ChartTooltipContent, {
        labelFormatter: formatBucket,
    }),
);
const tooltipTriggers = computed(() => ({
    [VisStackedBarSelectors.bar]: (bar: {
        datum: ChartPoint;
    }): string | undefined => {
        const point = bar.datum;
        return tooltipTemplate.value?.(point, point.index);
    },
}));
const barAttributes = {
    [VisStackedBarSelectors.bar]: { 'data-testid': 'analytics-post-bar' },
};
</script>

<template>
    <ChartContainer
        :config="chartConfig"
        cursor
        class="h-72 w-full sm:h-80"
        data-testid="posts-unovis-chart"
    >
        <VisXYContainer
            :data="chartData"
            :padding="{ top: 14, right: 8, bottom: 0, left: 0 }"
        >
            <VisStackedBar
                :x="xAccessor"
                :y="yAccessors"
                :color="barColors"
                :rounded-corners="4"
                :bar-padding="0.35"
                :attributes="barAttributes"
            />
            <VisAxis
                type="x"
                :tick-format="formatBucket"
                :tick-values="ticks"
                :grid-line="false"
                :domain-line="false"
                :tick-line="false"
            />
            <VisAxis
                type="y"
                :tick-format="formatCountTick"
                :num-ticks="5"
                :grid-line="true"
                :domain-line="false"
                :tick-line="false"
            />
            <ChartTooltip :triggers="tooltipTriggers" />
        </VisXYContainer>
    </ChartContainer>
</template>
