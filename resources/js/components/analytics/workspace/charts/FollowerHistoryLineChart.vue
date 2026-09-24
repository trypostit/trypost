<script setup lang="ts">
import { VisAxis, VisLine, VisScatter, VisXYContainer } from '@unovis/vue';
import { computed } from 'vue';

import {
    ChartContainer,
    ChartCrosshair,
    ChartTooltip,
    ChartTooltipContent,
    componentToString,
} from '@/components/ui/chart';
import date from '@/date';

import {
    accountColor,
    type FollowerAccount,
    type WorkspaceAnalyticsReport,
} from '../types';

import {
    formatCountTick,
    socialAccountChartConfig,
} from './socialAccountChart';

type ChartPoint = {
    date: string;
    index: number;
    [key: string]: string | number | undefined;
};

const props = defineProps<{
    accounts: FollowerAccount[];
    series: WorkspaceAnalyticsReport['followers']['series'];
    colors: Record<string, string>;
}>();

const chartData = computed<ChartPoint[]>(() =>
    props.series.map((point, index) => {
        const values: ChartPoint = { date: point.date, index };

        props.accounts.forEach((account, accountIndex) => {
            values[`account_${accountIndex}`] =
                point.accounts[account.social_account_key] ?? undefined;
        });

        return values;
    }),
);

const chartConfig = computed(() =>
    socialAccountChartConfig(props.accounts, props.colors),
);

const xAccessor = (point: ChartPoint): number => point.index;
const valueAccessor =
    (index: number) =>
    (point: ChartPoint): number | undefined => {
        const value = point[`account_${index}`];
        return typeof value === 'number' ? value : undefined;
    };
const formatDate = (tick: number | Date): string => {
    const index = typeof tick === 'number' ? Math.round(tick) : 0;
    const day = chartData.value[index]?.date;

    return day ? date.formatMonthDay(day) : '';
};
const tooltipTemplate = computed(() =>
    componentToString(chartConfig.value, ChartTooltipContent, {
        labelFormatter: formatDate,
    }),
);
</script>

<template>
    <div
        role="img"
        :aria-label="$t('analytics.dashboard.followers_line_description')"
    >
        <ChartContainer
            :config="chartConfig"
            cursor
            class="h-72 w-full sm:h-80"
            data-testid="followers-unovis-chart"
        >
            <VisXYContainer
                :data="chartData"
                :padding="{ top: 16, right: 12, bottom: 0, left: 0 }"
            >
                <template
                    v-for="(account, index) in accounts"
                    :key="account.social_account_key"
                >
                    <VisLine
                        :x="xAccessor"
                        :y="valueAccessor(index)"
                        :color="
                            colors[account.social_account_key] ??
                            accountColor(index)
                        "
                        :line-width="2.5"
                        curve-type="monotoneX"
                    />
                    <VisScatter
                        :x="xAccessor"
                        :y="valueAccessor(index)"
                        :color="
                            colors[account.social_account_key] ??
                            accountColor(index)
                        "
                        :size="6"
                    />
                </template>
                <VisAxis
                    type="x"
                    :tick-format="formatDate"
                    :num-ticks="Math.min(chartData.length, 6)"
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
                <ChartCrosshair
                    :template="tooltipTemplate"
                    color="var(--foreground)"
                    :circle-radius="4"
                />
                <ChartTooltip />
            </VisXYContainer>
        </ChartContainer>
    </div>
</template>
