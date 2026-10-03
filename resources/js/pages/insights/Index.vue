<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { IconTrendingUp } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import AnalyticsRangePresets from '@/components/analytics/AnalyticsRangePresets.vue';
import InsightsExportMenu from '@/components/analytics/InsightsExportMenu.vue';
import InsightsSyncStatus from '@/components/analytics/InsightsSyncStatus.vue';
import FollowersChart from '@/components/analytics/workspace/FollowersChart.vue';
import ImportCoverage from '@/components/analytics/workspace/ImportCoverage.vue';
import InsightsEmptyState from '@/components/analytics/workspace/InsightsEmptyState.vue';
import PerformanceTable from '@/components/analytics/workspace/PerformanceTable.vue';
import PostsChart from '@/components/analytics/workspace/PostsChart.vue';
import SummaryCards from '@/components/analytics/workspace/SummaryCards.vue';
import TopPosts from '@/components/analytics/workspace/TopPosts.vue';
import EmptyState from '@/components/EmptyState.vue';
import HeaderTitle from '@/components/HeaderTitle.vue';
import LabelFilter from '@/components/labels/LabelFilter.vue';
import PostChannelFilter from '@/components/posts/PostChannelFilter.vue';
import { useAnalyticsCoveragePoll } from '@/composables/useAnalyticsCoveragePoll';
import date from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { accountColor } from '@/lib/analyticsColors';
import { insights as insightsRoute } from '@/routes/app';
import type {
    AnalyticsChannelOption,
    InsightsSyncCadence,
    SummaryMetric,
    WorkspaceAnalyticsReport,
} from '@/types/analytics';

const props = defineProps<{
    report: WorkspaceAnalyticsReport;
    labels: { id: string; name: string; color: string }[];
    channelOptions: AnalyticsChannelOption[];
    availableMetrics: SummaryMetric[] | null;
    sync: InsightsSyncCadence;
}>();

const selectedLabelIds = ref<string[]>([...props.report.filters.labels]);
const selectedUntagged = ref<boolean>(props.report.filters.untagged);
const selectedChannelIds = ref<string[]>([...props.report.filters.channels]);

const filterQuery = computed((): Record<string, string | string[]> => ({
    ...(selectedChannelIds.value.length
        ? { channels: selectedChannelIds.value }
        : {}),
    ...(selectedLabelIds.value.length ? { labels: selectedLabelIds.value } : {}),
    ...(selectedUntagged.value ? { untagged: '1' } : {}),
}));

const filtered = computed(
    () =>
        props.report.filters.channels.length > 0 ||
        props.report.filters.labels.length > 0 ||
        props.report.filters.untagged,
);

const sameIds = (left: string[], right: string[]): boolean =>
    left.length === right.length && left.every((id) => right.includes(id));

const selectionMatchesServer = (): boolean =>
    sameIds(selectedChannelIds.value, props.report.filters.channels) &&
    sameIds(selectedLabelIds.value, props.report.filters.labels) &&
    selectedUntagged.value === props.report.filters.untagged;

watch(
    () => props.report.filters,
    (filters) => {
        if (!sameIds(selectedChannelIds.value, filters.channels)) {
            selectedChannelIds.value = [...filters.channels];
        }

        if (!sameIds(selectedLabelIds.value, filters.labels)) {
            selectedLabelIds.value = [...filters.labels];
        }

        selectedUntagged.value = filters.untagged;
    },
);

const rangeQuery = (): Record<string, string> =>
    props.report.filters.range === 'custom'
        ? {
              range: 'custom',
              start: props.report.filters.start,
              end: props.report.filters.end,
          }
        : { range: props.report.filters.range };

watch(
    [selectedChannelIds, selectedLabelIds, selectedUntagged],
    () => {
        if (selectionMatchesServer()) {
            return;
        }

        router.get(
            insightsRoute.url(),
            { ...rangeQuery(), ...filterQuery.value },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    },
    { deep: true },
);

useAnalyticsCoveragePoll(() => props.report.coverage);

const span = (range: { start: string; end: string }): string =>
    `${date.formatDayMonthYear(range.start)} – ${date.formatDayMonthYear(range.end)}`;

const accountColors = computed<Record<string, string>>(() => {
    const keys = [
        ...new Set([
            ...props.channelOptions.map((channel) => channel.analytics_key),
            ...[
                ...props.report.followers.accounts,
                ...props.report.posts.accounts,
            ].map((account) => account.social_account_key),
        ]),
    ];

    return Object.fromEntries(
        keys.map((key, index) => [key, accountColor(index)]),
    );
});
</script>

<template>
    <AppLayout full-width>
        <Head :title="$t('analytics.title')" />
        <div
            class="flex min-h-full min-w-0 shrink-0 flex-col gap-6 px-4 pt-6 pb-10 md:px-8"
        >
            <header
                class="sticky top-0 z-20 -mx-4 -mt-6 flex min-w-0 flex-col gap-2 border-b border-border bg-card px-4 pt-6 md:-mx-8 md:px-8"
                data-testid="analytics-page-header"
            >
                <div class="flex min-w-0 items-center justify-between gap-4">
                    <HeaderTitle
                        :title="$t('analytics.title')"
                        :icon="IconTrendingUp"
                    />
                    <div class="flex shrink-0 items-center gap-2">
                        <InsightsSyncStatus
                            :cadence="sync"
                            :coverage="report.coverage"
                        />
                        <InsightsExportMenu
                            :query="{ ...rangeQuery(), ...filterQuery }"
                        />
                    </div>
                </div>
                <div
                    class="flex min-h-12 min-w-0 flex-wrap items-center justify-between gap-2 pt-2 pb-3"
                    data-testid="analytics-toolbar"
                >
                    <AnalyticsRangePresets
                        :filters="report.filters"
                        :bounds="report.bounds"
                        :url="insightsRoute.url()"
                        :range="report.range"
                        :previous-range="report.previous_range"
                        :keep="filterQuery"
                        hide-caption
                    />
                    <div
                        class="flex min-w-0 flex-wrap items-center gap-2"
                        data-testid="analytics-filters"
                    >
                        <PostChannelFilter
                            v-model="selectedChannelIds"
                            :channels="channelOptions"
                            test-id="analytics-channel"
                        />
                        <LabelFilter
                            v-model="selectedLabelIds"
                            v-model:untagged="selectedUntagged"
                            :labels="labels"
                            test-id="analytics-label"
                        />
                    </div>
                </div>
            </header>

            <ImportCoverage :coverage="report.coverage" />

            <EmptyState
                v-if="!report.bounds.min && report.filters.channels.length"
                data-testid="analytics-filtered-empty-state"
                :icon="IconTrendingUp"
                :title="$t('analytics.dashboard.filtered_no_data_title')"
                :description="$t('analytics.dashboard.filtered_no_data_body')"
            />
            <InsightsEmptyState
                v-else-if="!report.bounds.min"
                :has-channels="channelOptions.length > 0"
            />

            <template v-else>
                <SummaryCards
                    :report="report"
                    :available-metrics="availableMetrics ?? undefined"
                    :subtitle="
                        $t('analytics.ranges.compared_to', {
                            current: span(report.range),
                            previous: span(report.previous_range),
                        })
                    "
                    subtitle-testid="insights-range-caption"
                />
                <TopPosts
                    :top-posts="report.top_posts"
                    :range="report.range"
                    :filtered="filtered"
                />
                <PerformanceTable
                    :rows="report.performance"
                    :range="report.range"
                    :previous-range="report.previous_range"
                    :filtered="filtered"
                />
                <FollowersChart
                    :followers="report.followers"
                    :range="report.range"
                    :colors="accountColors"
                    :filtered="report.filters.channels.length > 0"
                    :channel-filtered="report.filters.channels.length > 0"
                    :total-channels="channelOptions.length"
                />
                <PostsChart
                    :posts="report.posts"
                    :range="report.range"
                    :colors="accountColors"
                    :filtered="filtered"
                    :channel-filtered="report.filters.channels.length > 0"
                    :total-channels="channelOptions.length"
                />
            </template>
        </div>
    </AppLayout>
</template>
