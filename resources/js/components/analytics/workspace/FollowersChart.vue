<script setup lang="ts">
import { ref } from 'vue';

import { formatNumberCompact } from '@/lib/utils';
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
}>();
const observedDays = props.followers.series.filter((point) =>
    Object.values(point.accounts).some((value) => value !== null),
).length;
const mode = ref<'line' | 'bar' | 'growth'>(observedDays > 1 ? 'line' : 'bar');
const buttons = [
    {
        mode: 'line',
        label: 'analytics.dashboard.chart_line',
        test: 'followers-line',
    },
    {
        mode: 'bar',
        label: 'analytics.dashboard.chart_bar',
        test: 'followers-bar',
    },
    {
        mode: 'growth',
        label: 'analytics.dashboard.chart_growth',
        test: 'followers-growth',
    },
] as const;
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.dashboard.followers')"
        :range="range"
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
            class="rounded-lg border-2 border-dashed border-foreground/30 px-5 py-12 text-center text-sm text-muted-foreground"
        >
            {{ $t('analytics.dashboard.no_follower_data') }}
        </div>
        <div
            v-else
            class="min-w-0 rounded-xl border-2 border-foreground bg-card p-4 shadow-sm sm:p-5"
        >
            <div class="mb-4 flex flex-wrap items-baseline gap-2">
                <span
                    class="text-4xl font-semibold tracking-tight tabular-nums"
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
            <div class="min-w-0 border-t border-foreground/15 pt-5">
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
                />
            </div>
            <div
                class="mt-5 grid gap-x-5 gap-y-2.5 border-t border-foreground/15 pt-4 sm:grid-cols-2 xl:grid-cols-3"
            >
                <div
                    v-for="account in followers.accounts"
                    :key="account.social_account_key"
                    class="flex min-w-0 items-center justify-between gap-2"
                >
                    <AccountIdentity :account="account" />
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
