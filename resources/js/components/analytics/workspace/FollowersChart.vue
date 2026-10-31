<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import MetricChange from '@/components/analytics/MetricChange.vue';
import ShareChartButton from '@/components/analytics/ShareChartButton.vue';
import type { ChartTooltipDetail } from '@/components/ui/chart';
import date from '@/date';
import { activeLocale } from '@/language';
import {
    FOLLOWERS_ROWS,
    type FollowersChartMode,
    followersNet,
    followersShareOption,
} from '@/lib/insightsShare';
import type { ShareMetricOption } from '@/lib/insightsShareCard';
import {
    formatCountChange,
    formatNumberCompact,
    formatPercentChange,
    formatSignedNumber,
} from '@/lib/utils';
import type { WorkspaceAnalyticsReport } from '@/types/analytics';

import AnalyticsModeToggle from './AnalyticsModeToggle.vue';
import AnalyticsSection from './AnalyticsSection.vue';
import FollowerHistoryLineChart from './charts/FollowerHistoryLineChart.vue';
import SocialAccountMetricBarChart from './charts/SocialAccountMetricBarChart.vue';

const props = defineProps<{
    followers: WorkspaceAnalyticsReport['followers'];
    range: WorkspaceAnalyticsReport['range'];
    colors: Record<string, string>;
    filtered?: boolean;
    channelFiltered?: boolean;
    totalChannels?: number;
}>();
const mode = ref<FollowersChartMode>('bar');
const buttons = [
    {
        mode: 'bar',
        label: 'analytics.dashboard.chart_bar',
        test: 'followers-bar',
    },
    {
        mode: 'line',
        label: 'analytics.dashboard.chart_line',
        test: 'followers-line',
    },
    {
        mode: 'growth',
        label: 'analytics.dashboard.chart_growth',
        test: 'followers-growth',
    },
] as const;

/** A change's tone in the tooltip: green for a gain, red for a loss, none when flat. */
const changeTone = (change: number): ChartTooltipDetail['tone'] => {
    if (change > 0) {
        return 'up';
    }

    if (change < 0) {
        return 'down';
    }

    return undefined;
};

const changeLabel = (change: number): string => {
    if (change < 0) {
        return trans('analytics.dashboard.followers_lost_in_range');
    }

    return trans('analytics.dashboard.followers_gained_in_range');
};

/** What a channel gained or lost over the period, with its share of where it started when it started above zero. */
const changeDetail = (change: number, start: number): ChartTooltipDetail => {
    const label = changeLabel(change);
    const value = formatSignedNumber(change);

    if (start <= 0) {
        return { label, value, tone: changeTone(change) };
    }

    return {
        label,
        value: `${value} (${formatPercentChange((change / start) * 100)})`,
        tone: changeTone(change),
    };
};

/** The tooltip's rows under a channel in Bar view: where it started, and what it gained or lost. */
const barDetail = (index: number): ChartTooltipDetail[] => {
    const account = props.followers.accounts[index];
    const change = account?.net ?? null;

    if (mode.value !== 'bar' || !account || account.value === null || change === null) {
        return [];
    }

    const start = account.value - change;

    return [
        {
            label: trans('analytics.dashboard.followers_at_start'),
            value: start.toLocaleString(activeLocale.value),
        },
        changeDetail(change, start),
    ];
};
/** One bar per channel with its number: its followers (Bar) or its change over the period (Growth); none in Line. */
const barChart = computed(() => {
    if (mode.value === 'line') {
        return null;
    }

    const rows = FOLLOWERS_ROWS[mode.value];

    return {
        rows: props.followers.accounts.map((account) => ({
            account,
            value: rows.value(account),
        })),
        formatValue: rows.format,
    };
});
/** What the period added or took away across every channel, as the share card shows it. */
const net = computed(() => followersNet(props.followers));
const shownChannels = computed(() =>
    props.channelFiltered &&
    props.totalChannels &&
    props.followers.accounts.length < props.totalChannels
        ? props.followers.accounts.length
        : null,
);
const rangeLabel = computed(() => date.formatDayMonthYearRange(props.range));
const buildShareOptions = (): ShareMetricOption[] => [
    followersShareOption(
        props.followers,
        rangeLabel.value,
        mode.value,
        props.colors,
    ),
];
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.dashboard.followers')"
        :info="$t('analytics.insights.about.followers')"
        info-testid="analytics-followers-about"
        :range="shownChannels === null ? range : undefined"
        :subtitle="
            shownChannels === null
                ? undefined
                : $t('analytics.insights.channels_shown', {
                      range: rangeLabel,
                      shown: String(shownChannels),
                      total: String(totalChannels),
                  })
        "
        subtitle-testid="analytics-followers-subtitle"
    >
        <template #actions>
            <AnalyticsModeToggle
                v-model="mode"
                label="analytics.dashboard.followers_chart_mode"
                :options="buttons"
            />
            <ShareChartButton
                :range="rangeLabel"
                :build="buildShareOptions"
                test-id="share-followers"
            />
        </template>

        <div
            v-if="followers.accounts.length === 0"
            data-testid="analytics-followers-empty"
            class="rounded-lg border border-dashed border-border-strong px-5 py-12 text-center text-sm text-muted-foreground"
        >
            {{
                $t(
                    filtered
                        ? 'analytics.dashboard.filtered_no_followers'
                        : 'analytics.dashboard.no_follower_data',
                )
            }}
        </div>
        <div v-else class="min-w-0">
            <div class="mb-5 grid gap-1">
                <div class="flex flex-wrap items-baseline gap-2">
                    <span
                        class="font-heading text-[28px] leading-[35px] font-medium text-foreground tabular-nums"
                        >{{ formatNumberCompact(followers.total) }}</span
                    >
                    <span class="text-sm text-muted-foreground">{{
                        $t('analytics.dashboard.total_followers')
                    }}</span>
                </div>
                <MetricChange
                    v-if="net !== null"
                    class="justify-self-start"
                    data-testid="analytics-followers-change"
                    :change="net"
                    :label="formatCountChange(net)"
                />
            </div>
            <div class="min-w-0">
                <FollowerHistoryLineChart
                    v-if="mode === 'line'"
                    :accounts="followers.accounts"
                    :series="followers.series"
                    :colors="colors"
                />
                <SocialAccountMetricBarChart
                    v-else-if="barChart"
                    :rows="barChart.rows"
                    :colors="colors"
                    :detail="barDetail"
                    :format-value="barChart.formatValue"
                />
            </div>
        </div>
    </AnalyticsSection>
</template>
