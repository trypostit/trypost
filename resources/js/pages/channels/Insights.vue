<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconChartBar, IconChartBarOff } from '@tabler/icons-vue';
import { computed } from 'vue';

import AnalyticsRangePresets from '@/components/analytics/AnalyticsRangePresets.vue';
import ChannelPublicationTable from '@/components/analytics/channel/ChannelPublicationTable.vue';
import InsightsExportMenu from '@/components/analytics/InsightsExportMenu.vue';
import InsightsSyncStatus from '@/components/analytics/InsightsSyncStatus.vue';
import FollowersChart from '@/components/analytics/workspace/FollowersChart.vue';
import ImportCoverage from '@/components/analytics/workspace/ImportCoverage.vue';
import PostsChart from '@/components/analytics/workspace/PostsChart.vue';
import SummaryCards from '@/components/analytics/workspace/SummaryCards.vue';
import EmptyState from '@/components/EmptyState.vue';
import PublishHeader from '@/components/publish/PublishHeader.vue';
import { useAnalyticsCoveragePoll } from '@/composables/useAnalyticsCoveragePoll';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { accountColor } from '@/lib/analyticsColors';
import { insights } from '@/routes/app/channels';
import type {
    AnalyticsReport,
    ChannelInsightsFilters,
    ChannelPublicationRow,
    InsightsSyncCadence,
    SummaryMetric,
} from '@/types/analytics';
import { channelName } from '@/types/channel';
import type { PublishChannel } from '@/types/publish';

const props = defineProps<{
    channel: PublishChannel;
    supported: boolean;
    report?: AnalyticsReport;
    filters?: ChannelInsightsFilters;
    availableMetrics?: SummaryMetric[];
    sortableMetrics?: SummaryMetric[];
    publications?: { data: ChannelPublicationRow[] };
    sync?: InsightsSyncCadence;
}>();

useAnalyticsCoveragePoll(() => props.report?.coverage, {
    firstDay: () => props.report?.bounds.min,
    only: ['availableMetrics', 'publications', 'filters'],
    reset: ['publications'],
});

const url = computed(() => insights.url(props.channel.id));
const span = (range: { start: string; end: string }): string =>
    `${date.formatDayMonthYear(range.start)} – ${date.formatDayMonthYear(range.end)}`;
const keep = computed((): Record<string, string> => {
    if (!props.filters) {
        return {};
    }

    const { period, sort } = props.filters;

    return { period, sort };
});
const exportQuery = computed((): Record<string, string | string[]> => {
    if (!props.filters) {
        return { channels: [props.channel.id] };
    }

    const { range, start, end } = props.filters;

    return {
        range,
        ...(range === 'custom' ? { start, end } : {}),
        channels: [props.channel.id],
    };
});
const accountColors = computed<Record<string, string>>(() => {
    if (!props.report) {
        return {};
    }

    const keys = [
        ...new Set(
            [
                ...props.report.followers.accounts,
                ...props.report.posts.accounts,
            ].map((account) => account.social_account_key),
        ),
    ];

    return Object.fromEntries(
        keys.map((key, index) => [key, accountColor(index)]),
    );
});
</script>

<template>
    <Head
        :title="
            $t('analytics.channel.page_title', {
                channel: channelName(channel),
            })
        "
    />

    <AppLayout full-width>
        <template #header>
            <PublishHeader :channel="channel" />
        </template>

        <div
            class="flex min-h-full min-w-0 shrink-0 flex-col gap-6 px-4 pt-6 pb-10 md:px-8"
            data-testid="channel-insights"
        >
            <header
                class="-mb-2 flex min-w-0 flex-col gap-2"
                data-testid="insights-page-header"
            >
                <div class="flex min-w-0 items-center justify-between gap-4">
                    <h2
                        class="font-heading text-xl leading-tight font-medium text-foreground"
                    >
                        {{ $t('analytics.channel.title') }}
                    </h2>
                    <div
                        v-if="supported && report && sync"
                        class="flex shrink-0 items-center gap-2"
                    >
                        <InsightsSyncStatus
                            :cadence="sync"
                            :coverage="report.coverage"
                        />
                        <InsightsExportMenu :query="exportQuery" />
                    </div>
                </div>
                <div
                    v-if="report && filters"
                    class="flex min-h-12 min-w-0 items-center pt-2 pb-4"
                >
                    <AnalyticsRangePresets
                        :filters="filters"
                        :bounds="report.bounds"
                        :url="url"
                        :range="report.range"
                        :previous-range="report.previous_range"
                        :keep="keep"
                        hide-caption
                    />
                </div>
            </header>

            <EmptyState
                v-if="!supported"
                data-testid="insights-unsupported"
                :icon="IconChartBarOff"
                :title="$t('analytics.channel.unsupported_title')"
                :description="
                    $t('analytics.channel.unsupported_body', {
                        network: getPlatformLabel(channel.platform),
                    })
                "
            />

            <template v-else-if="report">
                <ImportCoverage :coverage="report.coverage" />

                <EmptyState
                    v-if="!report.bounds.min"
                    data-testid="insights-no-data"
                    :icon="IconChartBar"
                    :title="$t('analytics.dashboard.no_data_title')"
                    :description="$t('analytics.dashboard.no_data_body')"
                />

                <template v-else>
                    <SummaryCards
                        :report="report"
                        :available-metrics="availableMetrics ?? []"
                        :subtitle="
                            $t('analytics.ranges.compared_to', {
                                current: span(report.range),
                                previous: span(report.previous_range),
                            })
                        "
                        subtitle-testid="insights-range-caption"
                    />
                    <ChannelPublicationTable
                        v-if="filters"
                        :report="report"
                        :filters="filters"
                        :rows="publications?.data ?? []"
                        :available-metrics="availableMetrics ?? []"
                        :sortable-metrics="sortableMetrics ?? []"
                        :url="url"
                    />
                    <FollowersChart
                        :followers="report.followers"
                        :range="report.range"
                        :colors="accountColors"
                    />
                    <PostsChart
                        :posts="report.posts"
                        :range="report.range"
                        :colors="accountColors"
                    />
                </template>
            </template>
        </div>
    </AppLayout>
</template>
