<script setup lang="ts">
import { computed, ref } from 'vue';

import ShareChartButton from '@/components/analytics/ShareChartButton.vue';
import date from '@/date';
import {
    POSTS_ROWS,
    type PostsChartMode,
    postsShareOption,
} from '@/lib/insightsShare';
import type { ShareMetricOption } from '@/lib/insightsShareCard';
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
const mode = ref<PostsChartMode>('bar');
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
/** One bar per channel with the posts it sent. */
const barRows = computed(() =>
    props.posts.accounts.map((account) => ({
        account,
        value: POSTS_ROWS.value(account),
    })),
);
const rangeLabel = computed(() => date.formatDayMonthYearRange(props.range));
const buildShareOptions = (): ShareMetricOption[] => [
    postsShareOption(props.posts, rangeLabel.value, mode.value, props.colors),
];
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
            <ShareChartButton
                v-if="posts.accounts.length > 0"
                :range="rangeLabel"
                :build="buildShareOptions"
                test-id="share-posts"
            />
        </template>

        <div
            v-if="posts.accounts.length === 0"
            data-testid="analytics-posts-empty"
            class="rounded-lg border border-dashed border-border-strong px-5 py-12 text-center text-sm text-muted-foreground"
        >
            {{
                $t(
                    filtered
                        ? 'analytics.dashboard.filtered_no_posts'
                        : 'analytics.dashboard.no_post_data',
                )
            }}
        </div>
        <div v-else class="min-w-0">
            <div class="mb-5 flex flex-wrap items-baseline gap-2">
                <span
                    class="font-heading text-[28px] leading-[35px] font-medium text-foreground tabular-nums"
                    data-testid="analytics-posts-total"
                    >{{ formatNumberCompact(posts.total) }}</span
                >
                <span class="text-sm text-muted-foreground">{{
                    $t('analytics.dashboard.total_posts')
                }}</span>
            </div>
            <div class="min-w-0">
                <SocialAccountMetricBarChart
                    v-if="mode === 'bar'"
                    :rows="barRows"
                    :format-value="POSTS_ROWS.format"
                    :colors="colors"
                />
                <PostsOverTimeStackedBarChart
                    v-else
                    :accounts="posts.accounts"
                    :buckets="posts.buckets"
                    :resolution="posts.resolution"
                    :colors="colors"
                />
            </div>
            <div
                v-if="mode === 'stacked'"
                data-testid="analytics-posts-legend"
                class="mt-5 grid gap-x-8 gap-y-1 border-t border-border pt-4 sm:grid-cols-2 xl:grid-cols-3"
            >
                <div
                    v-for="account in posts.accounts"
                    :key="account.social_account_key"
                    class="-mx-2 flex min-h-9 min-w-0 items-center justify-between gap-2 rounded-md px-2 transition-control hover:bg-accent/60"
                >
                    <AccountIdentity
                        :account="account"
                        :color="colors[account.social_account_key]"
                        with-avatar
                        :avatar-size="24"
                    />
                    <span class="shrink-0 text-sm font-medium text-foreground tabular-nums">
                        {{ formatNumberCompact(account.count) }}
                    </span>
                </div>
            </div>
        </div>
    </AnalyticsSection>
</template>
