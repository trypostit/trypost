<script setup lang="ts">
import ChannelMetricsCard from '@/components/analytics/channel/ChannelMetricsCard.vue';
import ChannelPublicationTable from '@/components/analytics/channel/ChannelPublicationTable.vue';
import InsightsPrintCover from '@/components/analytics/InsightsPrintCover.vue';
import SummaryCards from '@/components/analytics/workspace/SummaryCards.vue';
import { provideInsightsPrint } from '@/composables/analytics/useInsightsPrint';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import type {
    AnalyticsReport,
    ChannelInsightsFilters,
    ChannelMetricSeries,
    ChannelPublicationPage,
    SummaryMetric,
} from '@/types/analytics';
import { channelName } from '@/types/channel';
import type { PublishChannel } from '@/types/publish';

const props = defineProps<{
    title: string;
    logoUrl: string | null;
    generatedAt: string;
    sections: string[];
    channel: PublishChannel;
    report: AnalyticsReport;
    filters: ChannelInsightsFilters;
    availableMetrics: SummaryMetric[];
    sortableMetrics: SummaryMetric[];
    columns: SummaryMetric[];
    publications: ChannelPublicationPage;
    metricSeries: ChannelMetricSeries;
}>();

provideInsightsPrint();

const shows = (section: string): boolean => props.sections.includes(section);
const span = (range: { start: string; end: string }): string =>
    `${date.formatDayMonthYear(range.start)} – ${date.formatDayMonthYear(range.end)}`;
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
            :available-metrics="availableMetrics"
            :subtitle="
                $t('analytics.ranges.compared_to', {
                    current: span(report.range),
                    previous: span(report.previous_range),
                })
            "
            :network="getPlatformLabel(channel.platform)"
            :channel="channelName(channel)"
        />
        <ChannelPublicationTable
            v-if="shows('posts')"
            :report="report"
            :filters="filters"
            :publications="publications"
            :available-metrics="availableMetrics"
            :sortable-metrics="sortableMetrics"
            url=""
            :platform="channel.platform"
            :initial-columns="columns.length ? columns : undefined"
        />
        <ChannelMetricsCard
            v-if="shows('metrics')"
            :series="metricSeries"
            :channel="channelName(channel)"
        />
    </InsightsPrintCover>
</template>
