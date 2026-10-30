<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import MetricChange from '@/components/analytics/MetricChange.vue';
import ShareChartButton from '@/components/analytics/ShareChartButton.vue';
import {
    shareChange,
    shareLogos,
    shareRange,
    summaryShareOption,
} from '@/lib/insightsShare';
import { formatNumberCompact, formatPercent } from '@/lib/utils';
import type { AnalyticsReport, SummaryMetric } from '@/types/analytics';

import AnalyticsSection from './AnalyticsSection.vue';
import InfoTip from './InfoTip.vue';

const props = defineProps<{
    report: AnalyticsReport;
    availableMetrics?: SummaryMetric[];
    subtitle?: string;
    subtitleTestid?: string;
    network?: string;
    channel?: string;
    platform?: string;
}>();

const defaultCards = computed(() => [
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

const cards = computed(() =>
    props.availableMetrics
        ? props.availableMetrics.map((key) => ({
              key,
              label: `analytics.channel.metrics.${key}.label`,
              metric: props.report.summary[key],
              percent: key === 'engagement_rate',
          }))
        : defaultCards.value,
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

const aboutKey = (key: string): string =>
    key === 'reactions' && props.network
        ? 'analytics.channel.metrics.reactions.about_network'
        : `analytics.channel.metrics.${key}.about`;

const testId = (key: string): string =>
    props.availableMetrics ? `insights-card-${key}` : `analytics-summary-${key}`;

const display = (value: number | null, percent: boolean): string =>
    value === null
        ? '—'
        : percent
          ? formatPercent(value)
          : formatNumberCompact(value);

const changeLabel = (key: string, change: number | null): string | null =>
    shareChange(change, key === 'followers')?.label ?? null;

const page = usePage();
const shareSubject = computed(
    () => props.channel ?? page.props.auth.currentWorkspace?.name ?? '',
);
const shareRangeLabel = computed(() => shareRange(props.report.range));
const shareLogoUrls = computed(() =>
    shareLogos(
        props.platform
            ? [props.platform]
            : props.report.followers.accounts.map((account) => account.platform),
    ),
);
const buildShareOptions = () => [
    summaryShareOption(
        cards.value.map((card) => ({
            label: trans(card.label),
            figure: display(card.metric.value, card.percent),
            change: shareChange(card.metric.change, card.key === 'followers'),
        })),
        { channel: props.channel ?? null },
        shareRangeLabel.value,
    ),
];
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.dashboard.summary')"
        :subtitle="subtitle"
        :subtitle-testid="subtitleTestid"
        flush
    >
        <template #actions>
            <ShareChartButton
                variant="summary"
                :subject="shareSubject"
                :range="shareRangeLabel"
                :logos="shareLogoUrls"
                :build="buildShareOptions"
                test-id="share-summary"
            />
        </template>
        <div
            class="grid grid-cols-2 border-t border-border md:grid-cols-3"
            :class="desktopColumns"
            data-testid="analytics-summary"
        >
            <div
                v-for="card in cards"
                :key="card.key"
                :data-testid="testId(card.key)"
                class="flex min-w-0 flex-col gap-2 px-4 py-4 shadow-[1px_0_0_var(--border),0_1px_0_var(--border)] sm:px-5"
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
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span
                        class="font-heading text-2xl leading-8 font-medium text-foreground tabular-nums"
                        >{{ display(card.metric.value, card.percent) }}</span
                    >
                    <MetricChange
                        v-if="changeLabel(card.key, card.metric.change)"
                        :data-testid="`${testId(card.key)}-change`"
                        :change="card.metric.change!"
                        :label="changeLabel(card.key, card.metric.change)!"
                    />
                </div>
            </div>
        </div>
        <p
            class="border-t border-border px-4 py-3 text-xs text-muted-foreground sm:px-5"
        >
            {{ $t('analytics.dashboard.latest_snapshot_hint') }}
        </p>
    </AnalyticsSection>
</template>
