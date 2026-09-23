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
    type ChartConfig,
} from '@/components/ui/chart';
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import { formatNumberCompact } from '@/lib/utils';

import { accountColor, type PostAccount, type PostBucket } from '../types';

type ChartPoint = {
    index: number;
    label: string;
    [key: string]: string | number;
};

const props = defineProps<{
    accounts: PostAccount[];
    buckets: PostBucket[];
    colors: Record<string, string>;
}>();

const chartData = computed<ChartPoint[]>(() =>
    props.buckets.map((bucket, index) => {
        const point: ChartPoint = { index, label: bucket.label };

        props.accounts.forEach((account, accountIndex) => {
            point[`account_${accountIndex}`] =
                bucket.accounts[account.social_account_key] ?? 0;
        });

        return point;
    }),
);
const chartConfig = computed<ChartConfig>(() =>
    Object.fromEntries(
        props.accounts.map((account, index) => [
            `account_${index}`,
            {
                label: account.username
                    ? `@${account.username}`
                    : account.name || account.platform,
                color:
                    props.colors[account.social_account_key] ??
                    accountColor(index),
                icon: getPlatformLogo(account.platform),
            },
        ]),
    ),
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
const formatCount = (tick: number | Date): string =>
    typeof tick === 'number' ? formatNumberCompact(tick) : '';
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
                :tick-format="formatCount"
                :num-ticks="5"
                :grid-line="true"
                :domain-line="false"
                :tick-line="false"
            />
            <ChartTooltip :triggers="tooltipTriggers" />
        </VisXYContainer>
    </ChartContainer>
</template>
