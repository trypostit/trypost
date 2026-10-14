<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { IconChartBar, IconChartBarOff } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import AnalyticsRangePresets from '@/components/analytics/AnalyticsRangePresets.vue';
import ChannelMetricsCard from '@/components/analytics/channel/ChannelMetricsCard.vue';
import ChannelPublicationTable from '@/components/analytics/channel/ChannelPublicationTable.vue';
import PostTypeFilter from '@/components/analytics/channel/PostTypeFilter.vue';
import InsightsExportMenu from '@/components/analytics/InsightsExportMenu.vue';
import InsightsSyncStatus from '@/components/analytics/InsightsSyncStatus.vue';
import ImportCoverage from '@/components/analytics/workspace/ImportCoverage.vue';
import SummaryCards from '@/components/analytics/workspace/SummaryCards.vue';
import EmptyState from '@/components/EmptyState.vue';
import LabelFilter from '@/components/labels/LabelFilter.vue';
import PublishHeader from '@/components/publish/PublishHeader.vue';
import { useAnalyticsCoveragePoll } from '@/composables/useAnalyticsCoveragePoll';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { showsPostTypeFilter } from '@/lib/channelInsights';
import { insights } from '@/routes/app/channels';
import type {
    AnalyticsReport,
    ChannelInsightsFilters,
    ChannelMetricSeries,
    ChannelPublicationPage,
    ContentTypeOption,
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
    publications?: ChannelPublicationPage;
    sync?: InsightsSyncCadence;
    labels?: { id: string; name: string; color: string }[];
    contentTypes?: ContentTypeOption[];
    metricSeries?: ChannelMetricSeries;
}>();

useAnalyticsCoveragePoll(() => props.report?.coverage, {
    firstDay: () => props.report?.bounds.min,
    only: ['availableMetrics', 'publications', 'filters', 'metricSeries'],
});

const url = computed(() => insights.url(props.channel.id));
const span = (range: { start: string; end: string }): string =>
    `${date.formatDayMonthYear(range.start)} – ${date.formatDayMonthYear(range.end)}`;
const selectedLabelIds = ref<string[]>(props.filters?.labels ?? []);
const selectedUntagged = ref<boolean>(props.filters?.untagged ?? false);
const selectedTypes = ref<string[]>(props.filters?.types ?? []);
const showsTypeFilter = computed(
    () =>
        showsPostTypeFilter(props.channel.platform) ||
        selectedTypes.value.length > 0,
);
const publicationFilters = computed(
    (): Record<string, string | string[]> => ({
        ...(selectedLabelIds.value.length
            ? { labels: selectedLabelIds.value }
            : {}),
        ...(selectedUntagged.value ? { untagged: '1' } : {}),
        ...(selectedTypes.value.length ? { types: selectedTypes.value } : {}),
    }),
);
const keep = computed((): Record<string, string | string[]> => {
    if (!props.filters) {
        return {};
    }

    const { period, sort } = props.filters;

    return { period, sort, ...publicationFilters.value };
});
const exportQuery = computed((): Record<string, string | string[]> => {
    if (!props.filters) {
        return {};
    }

    const { range, start, end } = props.filters;

    return {
        range,
        ...(range === 'custom' ? { start, end } : {}),
        ...publicationFilters.value,
    };
});

const applyFilters = (): void => {
    if (!props.filters) {
        return;
    }

    const { range, start, end } = props.filters;

    router.get(
        url.value,
        {
            range,
            ...(range === 'custom' ? { start, end } : {}),
            ...keep.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const sameIds = (left: string[], right: string[]): boolean =>
    left.length === right.length && left.every((id) => right.includes(id));

watch(
    () => props.filters,
    (filters) => {
        if (!filters) {
            return;
        }

        if (!sameIds(selectedLabelIds.value, filters.labels)) {
            selectedLabelIds.value = [...filters.labels];
        }

        if (selectedUntagged.value !== filters.untagged) {
            selectedUntagged.value = filters.untagged;
        }

        if (!sameIds(selectedTypes.value, filters.types)) {
            selectedTypes.value = [...filters.types];
        }
    },
);

watch(selectedLabelIds, applyFilters, { deep: true });
watch(selectedUntagged, applyFilters);
watch(selectedTypes, applyFilters, { deep: true });
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
                class="sticky top-0 z-20 -mx-4 -mt-6 flex min-w-0 flex-col gap-2 bg-card px-4 pt-6 md:-mx-8 md:px-8"
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
                        <InsightsExportMenu
                            :query="exportQuery"
                            :channel-id="channel.id"
                        />
                    </div>
                </div>
                <div
                    v-if="report && filters"
                    class="flex min-h-12 min-w-0 items-center justify-between gap-2 border-b border-border-strong pt-2 pb-3"
                    data-testid="insights-toolbar"
                >
                    <AnalyticsRangePresets
                        :filters="filters"
                        :bounds="report.bounds"
                        :url="url"
                        :range="report.range"
                        :keep="keep"
                    />
                    <div
                        class="flex shrink-0 items-center gap-2"
                        data-testid="insights-publication-filters"
                    >
                        <LabelFilter
                            v-model="selectedLabelIds"
                            v-model:untagged="selectedUntagged"
                            :labels="labels ?? []"
                            test-id="insights-label"
                        />
                        <PostTypeFilter
                            v-if="showsTypeFilter"
                            v-model="selectedTypes"
                            :types="contentTypes ?? []"
                        />
                    </div>
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
                        :network="getPlatformLabel(channel.platform)"
                    />
                    <ChannelPublicationTable
                        v-if="filters"
                        :report="report"
                        :filters="filters"
                        :publications="publications"
                        :available-metrics="availableMetrics ?? []"
                        :sortable-metrics="sortableMetrics ?? []"
                        :url="url"
                        :platform="channel.platform"
                    />
                    <ChannelMetricsCard :series="metricSeries" />
                </template>
            </template>
        </div>
    </AppLayout>
</template>
