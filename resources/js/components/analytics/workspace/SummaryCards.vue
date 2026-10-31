<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import MetricChange from '@/components/analytics/MetricChange.vue';
import ShareChartButton from '@/components/analytics/ShareChartButton.vue';
import date from '@/date';
import {
    countDelta,
    type MetricDelta,
    percentDelta,
} from '@/lib/channelMetrics';
import { summaryShareOption } from '@/lib/insightsShare';
import type { ShareMetricOption } from '@/lib/insightsShareCard';
import { formatNumberCompact, formatPercent } from '@/lib/utils';
import type { AnalyticsReport, SummaryMetric } from '@/types/analytics';

import AnalyticsSection from './AnalyticsSection.vue';
import InfoTip from './InfoTip.vue';

const props = defineProps<{
    report: AnalyticsReport;
    availableMetrics?: SummaryMetric[];
    subtitleTestid?: string;
    network?: string;
    channel?: string;
}>();

/** A Summary card: which metric, its label key, its numbers and whether it reads as a percentage. */
type SummaryCard = {
    key: SummaryMetric;
    label: string;
    metric: AnalyticsReport['summary'][SummaryMetric];
    percent: boolean;
};

const defaultCards = computed((): SummaryCard[] => [
    {
        key: 'posts',
        label: 'analytics.dashboard.posts',
        metric: props.report.summary.posts,
        percent: false,
    },
    {
        key: 'followers',
        label: 'analytics.dashboard.total_followers',
        metric: props.report.summary.followers,
        percent: false,
    },
    {
        key: 'reactions',
        label: 'analytics.dashboard.reactions',
        metric: props.report.summary.reactions,
        percent: false,
    },
    {
        key: 'comments',
        label: 'analytics.dashboard.comments',
        metric: props.report.summary.comments,
        percent: false,
    },
    {
        key: 'engagement_rate',
        label: 'analytics.dashboard.engagement_rate',
        metric: props.report.summary.engagement_rate,
        percent: true,
    },
]);

/** A card's change as the page and the share card label it: followers as a count, the rest as a percentage. */
const cardChange = (key: SummaryMetric, change: number | null): MetricDelta | null => {
    if (change === null) {
        return null;
    }

    if (key === 'followers') {
        return countDelta(change);
    }

    return percentDelta(change);
};

const listedCards = computed((): SummaryCard[] => {
    if (!props.availableMetrics) {
        return defaultCards.value;
    }

    return props.availableMetrics.map((key) => ({
        key,
        label: `analytics.channel.metrics.${key}.label`,
        metric: props.report.summary[key],
        percent: key === 'engagement_rate',
    }));
});

const cards = computed(() =>
    listedCards.value.map((card) => ({
        ...card,
        change: cardChange(card.key, card.metric.change),
    })),
);

const desktopColumns = computed((): string => {
    const count = cards.value.length;

    if (count <= 3) {
        return 'lg:grid-cols-3';
    }

    if (count === 4 || (count > 5 && count <= 8)) {
        return 'lg:grid-cols-4';
    }

    return 'lg:grid-cols-5';
});

/** On a phone two cards share a row; an odd count leaves the last one alone, so it takes the whole row. */
const spansPhoneRow = (index: number): boolean =>
    cards.value.length % 2 === 1 && index === cards.value.length - 1;

/** A half-width card on a phone stacks its change under the number; a card with the whole row keeps them side by side. */
const valueRowClass = (index: number): string => {
    if (spansPhoneRow(index)) {
        return 'flex flex-wrap items-center gap-x-3 gap-y-1';
    }

    return 'flex flex-col items-start gap-1 md:flex-row md:flex-wrap md:items-center md:gap-x-3';
};

const aboutKey = (key: string): string =>
    key === 'reactions' && props.network
        ? 'analytics.channel.metrics.reactions.about_network'
        : `analytics.channel.metrics.${key}.about`;

const testId = (key: string): string =>
    props.availableMetrics ? `insights-card-${key}` : `analytics-summary-${key}`;

const display = (value: number | null, percent: boolean): string => {
    if (percent) {
        return formatPercent(value);
    }

    return formatNumberCompact(value);
};

/** A number for the share card: null while the metric has no value, so the card draws it muted. */
const tileFigure = (value: number | null, percent: boolean): string | null => {
    if (value === null) {
        return null;
    }

    return display(value, percent);
};

const rangeLabel = computed(() =>
    date.formatDayMonthYearRange(props.report.range),
);
const buildShareOption = (): ShareMetricOption =>
    summaryShareOption(
        cards.value.map((card) => ({
            label: trans(card.label),
            figure: tileFigure(card.metric.value, card.percent),
            change: card.change,
        })),
        props.channel ?? null,
        rangeLabel.value,
    );
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.dashboard.summary')"
        :range="report.range"
        :previous-range="report.previous_range"
        :subtitle-testid="subtitleTestid"
        flush
    >
        <template #actions>
            <ShareChartButton
                :subject="channel"
                :range="rangeLabel"
                :build="buildShareOption"
                test-id="share-summary"
            />
        </template>
        <div
            class="grid grid-cols-2 border-t border-border md:grid-cols-3"
            :class="desktopColumns"
            data-testid="analytics-summary"
        >
            <div
                v-for="(card, index) in cards"
                :key="card.key"
                :data-testid="testId(card.key)"
                class="flex min-w-0 flex-col gap-2 px-4 py-4 shadow-[1px_0_0_var(--border),0_1px_0_var(--border)] sm:px-5"
                :class="{ 'max-md:col-span-2': spansPhoneRow(index) }"
            >
                <div class="flex min-h-6 items-center gap-0.5">
                    <p class="truncate text-sm text-muted-foreground">
                        {{ $t(card.label) }}
                    </p>
                    <InfoTip
                        :text="$t(aboutKey(card.key), { network: network ?? '' })"
                        :test-id="`${testId(card.key)}-about`"
                    />
                </div>
                <div :class="valueRowClass(index)">
                    <span
                        class="font-heading text-2xl leading-8 font-medium text-foreground tabular-nums"
                        :data-testid="`${testId(card.key)}-value`"
                        >{{ display(card.metric.value, card.percent) }}</span
                    >
                    <MetricChange
                        v-if="card.change"
                        :data-testid="`${testId(card.key)}-change`"
                        :change="card.change.value"
                        :label="card.change.label"
                    />
                </div>
            </div>
        </div>
    </AnalyticsSection>
</template>
