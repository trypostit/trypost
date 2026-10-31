<script setup lang="ts">
import { computed } from 'vue';

import InsightsPrintCover from '@/components/analytics/InsightsPrintCover.vue';
import FollowersChart from '@/components/analytics/workspace/FollowersChart.vue';
import PerformanceTable from '@/components/analytics/workspace/PerformanceTable.vue';
import PostsChart from '@/components/analytics/workspace/PostsChart.vue';
import SummaryCards from '@/components/analytics/workspace/SummaryCards.vue';
import TopPosts from '@/components/analytics/workspace/TopPosts.vue';
import { provideInsightsPrint } from '@/composables/analytics/useInsightsPrint';
import {
    type PerformanceMetric,
    type PerformanceSortDirection,
    type PerformanceSortKey,
    usePerformanceColumns,
} from '@/composables/usePerformanceColumns';
import date from '@/date';
import { accountColors } from '@/lib/analyticsColors';
import type {
    AnalyticsChannelOption,
    SummaryMetric,
    WorkspaceAnalyticsReport,
} from '@/types/analytics';

const props = defineProps<{
    title: string;
    logoUrl: string | null;
    generatedAt: string;
    sections: string[];
    report: WorkspaceAnalyticsReport;
    channelOptions: AnalyticsChannelOption[];
    availableMetrics: SummaryMetric[] | null;
    columns: PerformanceMetric[];
    sort: PerformanceSortKey;
    direction: PerformanceSortDirection;
}>();

provideInsightsPrint();

const { columns: performanceColumns } = usePerformanceColumns();

performanceColumns.value = props.columns;

const shows = (section: string): boolean => props.sections.includes(section);
const filtered = computed(() => props.report.filters.channels.length > 0);
const span = (range: { start: string; end: string }): string =>
    `${date.formatDayMonthYear(range.start)} – ${date.formatDayMonthYear(range.end)}`;
const colors = computed<Record<string, string>>(() =>
    accountColors([
        ...props.channelOptions.map((channel) => channel.analytics_key),
        ...[...props.report.followers.accounts, ...props.report.posts.accounts].map(
            (account) => account.social_account_key,
        ),
    ]),
);
</script>

<template>
    <InsightsPrintCover
        :title="title"
        :logo-url="logoUrl"
        :generated-at="generatedAt"
        :range="report.range"
        :previous-range="report.previous_range"
    >
        <SummaryCards
            v-if="shows('summary')"
            :report="report"
            :available-metrics="availableMetrics ?? undefined"
            :subtitle="
                $t('analytics.ranges.compared_to', {
                    current: span(report.range),
                    previous: span(report.previous_range),
                })
            "
        />
        <TopPosts
            v-if="shows('top_posts_reactions')"
            :top-posts="report.top_posts"
            :range="report.range"
            :filtered="filtered"
            initial-metric="reactions"
        />
        <TopPosts
            v-if="shows('top_posts_comments')"
            :top-posts="report.top_posts"
            :range="report.range"
            :filtered="filtered"
            initial-metric="comments"
        />
        <PerformanceTable
            v-if="shows('performance')"
            :rows="report.performance"
            :range="report.range"
            :previous-range="report.previous_range"
            :filtered="filtered"
            :initial-sort-by="sort"
            :initial-sort-direction="direction"
        />
        <FollowersChart
            v-if="shows('followers')"
            :followers="report.followers"
            :range="report.range"
            :colors="colors"
            :filtered="filtered"
            :channel-filtered="filtered"
            :total-channels="channelOptions.length"
        />
        <PostsChart
            v-if="shows('posts_over_time')"
            :posts="report.posts"
            :range="report.range"
            :colors="colors"
            :filtered="filtered"
            :channel-filtered="filtered"
            :total-channels="channelOptions.length"
        />
    </InsightsPrintCover>
</template>
