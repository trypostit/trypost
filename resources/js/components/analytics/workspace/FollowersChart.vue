<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import date from '@/date';
import { activeLocale } from '@/language';
import { formatNumberCompact, formatPercentChange } from '@/lib/utils';
import type { WorkspaceAnalyticsReport } from '@/types/analytics';

import AccountIdentity from './AccountIdentity.vue';
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
const mode = ref<'bar' | 'line' | 'growth'>('bar');
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

const barDetail = (index: number): string | null => {
    const account = props.followers.accounts[index];

    if (
        mode.value !== 'bar' ||
        !account ||
        account.value === null ||
        account.growth === null
    ) {
        return null;
    }

    const start = account.value - account.growth;
    const count = (value: number): string =>
        value.toLocaleString(activeLocale.value);

    return trans(
        account.growth < 0
            ? 'analytics.dashboard.followers_lost_detail'
            : 'analytics.dashboard.followers_gained_detail',
        {
            start: count(start),
            change: count(Math.abs(account.growth)),
            percent:
                start > 0
                    ? formatPercentChange((account.growth / start) * 100)
                    : '—',
        },
    );
};
const shownChannels = computed(() =>
    props.channelFiltered &&
    props.totalChannels &&
    props.followers.accounts.length < props.totalChannels
        ? props.followers.accounts.length
        : null,
);
const rangeLabel = computed(
    () =>
        `${date.formatDayMonthYear(props.range.start)} – ${date.formatDayMonthYear(props.range.end)}`,
);
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
        </template>

        <div
            v-if="followers.accounts.length === 0"
            data-testid="analytics-followers-empty"
            class="rounded-lg border border-dashed border-border-strong bg-card px-5 py-12 text-center text-sm text-muted-foreground"
        >
            {{
                $t(
                    filtered
                        ? 'analytics.dashboard.filtered_no_followers'
                        : 'analytics.dashboard.no_follower_data',
                )
            }}
        </div>
        <div
            v-else
            class="min-w-0 rounded-lg border border-border bg-card p-4 sm:p-5"
        >
            <div class="mb-4 flex flex-wrap items-baseline gap-2">
                <span
                    class="font-heading text-[28px] leading-[35px] font-medium tabular-nums"
                    >{{
                        followers.total === null
                            ? '—'
                            : formatNumberCompact(followers.total)
                    }}</span
                >
                <span class="text-sm text-muted-foreground">{{
                    $t('analytics.dashboard.total_followers')
                }}</span>
            </div>
            <div class="min-w-0 border-t border-border pt-5">
                <FollowerHistoryLineChart
                    v-if="mode === 'line'"
                    :accounts="followers.accounts"
                    :series="followers.series"
                    :colors="colors"
                />
                <SocialAccountMetricBarChart
                    v-else
                    :rows="
                        followers.accounts.map((account) => ({
                            account,
                            value:
                                mode === 'growth'
                                    ? account.growth
                                    : account.value,
                        }))
                    "
                    :colors="colors"
                    :detail="barDetail"
                />
            </div>
            <div
                class="mt-5 grid gap-x-5 gap-y-2.5 border-t border-border pt-4 sm:grid-cols-2 xl:grid-cols-3"
            >
                <div
                    v-for="account in followers.accounts"
                    :key="account.social_account_key"
                    class="flex min-w-0 items-center justify-between gap-2"
                >
                    <AccountIdentity
                        :account="account"
                        :color="colors[account.social_account_key]"
                    />
                    <span class="flex shrink-0 items-center gap-2">
                        <span class="text-sm font-semibold tabular-nums">
                            {{
                                mode === 'growth'
                                    ? account.growth === null
                                        ? '—'
                                        : `${account.growth > 0 ? '+' : ''}${formatNumberCompact(account.growth)}`
                                    : account.value === null
                                      ? '—'
                                      : formatNumberCompact(account.value)
                            }}
                        </span>
                        <span
                            v-if="account.provenance === 'carried_forward'"
                            class="text-[11px] text-muted-foreground"
                            :title="
                                $t('analytics.dashboard.carried_forward_hint')
                            "
                            >{{
                                $t('analytics.dashboard.carried_forward')
                            }}</span
                        >
                    </span>
                </div>
            </div>
        </div>
    </AnalyticsSection>
</template>
