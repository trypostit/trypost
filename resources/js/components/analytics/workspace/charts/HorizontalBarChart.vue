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

import { accountColor, type AccountIdentityData } from '../types';

const props = defineProps<{
    rows: { account: AccountIdentityData; value: number | null }[];
    growth?: boolean;
    colors: Record<string, string>;
}>();

type BarPoint = { index: number; value: number };
const chartData = computed<BarPoint[]>(() =>
    props.rows.map((row, index) => ({
        index,
        value: row.value ?? 0,
    })),
);
const chartConfig = computed<ChartConfig>(() =>
    Object.fromEntries(
        props.rows.map((row, index) => [
            `account_${index}`,
            {
                label: row.account.username
                    ? `@${row.account.username}`
                    : row.account.name || row.account.platform,
                color:
                    props.colors[row.account.social_account_key] ??
                    accountColor(index),
                icon: getPlatformLogo(row.account.platform),
            },
        ]),
    ),
);
const chartHeight = computed(
    () => `${Math.max(240, props.rows.length * 38 + 55)}px`,
);
const indexAccessor = (point: BarPoint): number => point.index;
const valueAccessor = (point: BarPoint): number => point.value;
const colorAccessor = (point: BarPoint): string => {
    const key = props.rows[point.index]?.account.social_account_key;
    return (key && props.colors[key]) || accountColor(point.index);
};
const formatCount = (tick: number | Date): string =>
    typeof tick === 'number' ? formatNumberCompact(tick) : '';
const formatAccount = (tick: number | Date): string => {
    const index = typeof tick === 'number' ? Math.round(tick) : 0;
    const account = props.rows[index]?.account;
    if (!account) return '';

    const label = account.username
        ? `@${account.username}`
        : account.name || account.platform;
    return label.length > 12 ? `${label.slice(0, 11)}…` : label;
};
const categoryTicks = computed(() => props.rows.map((_, index) => index));
const tooltipTemplate = computed(() =>
    componentToString(chartConfig.value, ChartTooltipContent),
);
const tooltipTriggers = computed(() => ({
    [VisStackedBarSelectors.bar]: (bar: {
        datum: BarPoint;
    }): string | undefined => {
        const point = bar.datum;
        return tooltipTemplate.value?.({
            [`account_${point.index}`]: props.rows[point.index]?.value,
        });
    },
}));
</script>

<template>
    <ChartContainer
        :config="chartConfig"
        class="w-full"
        :style="{ height: chartHeight }"
        data-testid="accounts-unovis-bar-chart"
    >
        <VisXYContainer
            :data="chartData"
            y-direction="south"
            :padding="{ top: 12, right: 16, bottom: 0, left: 0 }"
        >
            <VisStackedBar
                :x="indexAccessor"
                :y="valueAccessor"
                :color="colorAccessor"
                orientation="horizontal"
                :rounded-corners="4"
                :bar-padding="0.32"
            />
            <VisAxis
                type="x"
                :tick-format="formatCount"
                :num-ticks="5"
                :grid-line="true"
                :domain-line="false"
                :tick-line="false"
            />
            <VisAxis
                type="y"
                :tick-format="formatAccount"
                :tick-values="categoryTicks"
                :grid-line="false"
                :domain-line="false"
                :tick-line="false"
            />
            <ChartTooltip :triggers="tooltipTriggers" />
        </VisXYContainer>
    </ChartContainer>
</template>
