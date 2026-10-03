<script setup lang="ts">
import { computed, ref } from 'vue';

import date from '@/date';
import { formatNumberCompact } from '@/lib/utils';
import type { WorkspaceAnalyticsReport } from '@/types/analytics';

import AccountIdentity from './AccountIdentity.vue';
import AnalyticsModeToggle from './AnalyticsModeToggle.vue';
import AnalyticsSection from './AnalyticsSection.vue';
import PostsOverTimeStackedBarChart from './charts/PostsOverTimeStackedBarChart.vue';
import SocialAccountMetricBarChart from './charts/SocialAccountMetricBarChart.vue';

const props = defineProps<{
    posts: WorkspaceAnalyticsReport['posts'];
    range: WorkspaceAnalyticsReport['range'];
    colors: Record<string, string>;
    filtered?: boolean;
    channelFiltered?: boolean;
    totalChannels?: number;
}>();
const mode = ref<'bar' | 'stacked'>('bar');
const buttons = [
    { mode: 'bar', label: 'analytics.dashboard.chart_bar', test: 'posts-bar' },
    {
        mode: 'stacked',
        label: 'analytics.dashboard.chart_stacked_bar',
        test: 'posts-stacked',
    },
] as const;
const shownChannels = computed(() =>
    props.channelFiltered &&
    props.totalChannels &&
    props.posts.accounts.length < props.totalChannels
        ? props.posts.accounts.length
        : null,
);
const rangeLabel = computed(
    () =>
        `${date.formatDayMonthYear(props.range.start)} – ${date.formatDayMonthYear(props.range.end)}`,
);
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.dashboard.posts')"
        :info="$t('analytics.insights.about.posts')"
        info-testid="analytics-posts-about"
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
        subtitle-testid="analytics-posts-subtitle"
    >
        <template #actions>
            <AnalyticsModeToggle
                v-model="mode"
                label="analytics.dashboard.posts_chart_mode"
                :options="buttons"
            />
        </template>

        <div
            v-if="posts.accounts.length === 0"
            data-testid="analytics-posts-empty"
            class="rounded-lg border border-dashed border-border-strong bg-card px-5 py-12 text-center text-sm text-muted-foreground"
        >
            {{
                $t(
                    filtered
                        ? 'analytics.dashboard.filtered_no_posts'
                        : 'analytics.dashboard.no_post_data',
                )
            }}
        </div>
        <div
            v-else
            class="min-w-0 rounded-lg border border-border bg-card p-4 sm:p-5"
        >
            <div class="min-w-0">
                <SocialAccountMetricBarChart
                    v-if="mode === 'bar'"
                    :rows="
                        posts.accounts.map((account) => ({
                            account,
                            value: account.count,
                        }))
                    "
                    :colors="colors"
                    :value-label="$t('analytics.dashboard.posts_sent_axis')"
                />
                <PostsOverTimeStackedBarChart
                    v-else
                    :accounts="posts.accounts"
                    :buckets="posts.buckets"
                    :resolution="posts.resolution"
                    :colors="colors"
                    :value-label="$t('analytics.dashboard.posts')"
                />
            </div>
            <div
                class="mt-5 grid gap-x-5 gap-y-2.5 border-t border-border pt-4 sm:grid-cols-2 xl:grid-cols-3"
            >
                <div
                    v-for="account in posts.accounts"
                    :key="account.social_account_key"
                    class="flex min-w-0 items-center justify-between gap-2"
                >
                    <AccountIdentity
                        :account="account"
                        :color="colors[account.social_account_key]"
                    />
                    <span class="shrink-0 text-sm font-semibold tabular-nums">
                        {{ formatNumberCompact(account.count) }}
                    </span>
                </div>
            </div>
        </div>
    </AnalyticsSection>
</template>
