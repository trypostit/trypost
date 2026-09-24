<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import date from '@/date';
import dayjs from '@/dayjs';
import { formatNumberCompact, formatPercent } from '@/lib/utils';

import AnalyticsSection from './AnalyticsSection.vue';
import type {
    PublicationAnalyticsDetail,
    PublicationMetricFact,
} from './types';

const props = defineProps<{ detail: PublicationAnalyticsDetail }>();

const groups = [
    {
        label: 'analytics.detail.engagement',
        keys: [
            'reactions',
            'comments',
            'replies',
            'shares',
            'reposts',
            'quotes',
            'saves',
            'bookmarks',
            'engagements',
            'total_interactions',
            'engagement_rate',
            'clicks',
            'link_clicks',
            'pin_clicks',
            'pin_click_rate',
            'outbound_clicks',
            'outbound_click_rate',
            'save_rate',
            'follows',
            'profile_visits',
            'profile_activity',
        ],
    },
    {
        label: 'analytics.detail.exposure',
        keys: [
            'views',
            'video_views',
            'impressions',
            'reach',
            'total_audience',
            'engaged_audience',
            'unique_viewers',
        ],
    },
    {
        label: 'analytics.detail.video',
        keys: [
            'watch_time_milliseconds',
            'average_watch_time_milliseconds',
            'total_play_time_milliseconds',
            'average_video_play_time_milliseconds',
            'average_percentage_viewed',
            'skip_rate',
            'engaged_views',
            'video_views_10_seconds',
            'video_views_95_percent',
            'video_quartile_25',
            'video_quartile_50',
            'video_quartile_75',
            'video_quartile_100',
            'subscribers_gained',
            'subscribers_lost',
            'story_navigation',
            'story_taps_forward',
            'story_taps_back',
            'story_exits',
            'story_swipes_forward',
        ],
    },
] as const;

const visibleGroups = computed(() =>
    groups
        .map((group) => ({
            ...group,
            metrics: group.keys.flatMap((key) => {
                const fact = props.detail.metrics[key];
                return fact?.availability === 'available' && fact.value !== null
                    ? [{ key, fact }]
                    : [];
            }),
        }))
        .filter((group) => group.metrics.length > 0),
);

const label = (key: string): string => {
    const core = `analytics.metrics.${key}`;
    const coreLabel = trans(core);
    if (coreLabel !== core) return coreLabel;

    const specialized = `analytics.detail.labels.${key}`;
    const specializedLabel = trans(specialized);
    return specializedLabel;
};

const display = (key: string, fact: PublicationMetricFact): string => {
    if (fact.value === null) return '—';
    if (fact.unit === 'percent') return formatPercent(fact.value);
    if (fact.unit === 'milliseconds') {
        const average = key.includes('average');
        return `${formatNumberCompact(fact.value / (average ? 1000 : 60000))} ${average ? 's' : 'min'}`;
    }
    return formatNumberCompact(fact.value);
};

const stale = computed(() =>
    props.detail.snapshot?.collected_at
        ? dayjs().diff(dayjs(props.detail.snapshot.collected_at), 'hour') > 48
        : false,
);
</script>

<template>
    <div class="flex flex-col gap-6">
        <div
            v-if="detail.snapshot"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground"
        >
            <span
                >{{ $t('analytics.detail.last_collected') }}
                <time :datetime="detail.snapshot.collected_at || undefined">{{
                    detail.snapshot.collected_at
                        ? date.formatDateTime(detail.snapshot.collected_at)
                        : detail.snapshot.date
                }}</time></span
            >
            <span
                v-if="stale"
                class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-900 dark:bg-amber-900/30 dark:text-amber-200"
                >{{ $t('analytics.detail.stale') }}</span
            >
        </div>

        <p
            v-if="visibleGroups.length === 0"
            class="rounded-xl border-2 border-dashed border-foreground/35 bg-card px-6 py-12 text-center text-sm text-muted-foreground"
        >
            {{ $t('analytics.detail.awaiting_metrics') }}
        </p>
        <AnalyticsSection
            v-for="group in visibleGroups"
            :key="group.label"
            :title="$t(group.label)"
        >
            <div
                class="grid gap-3"
                :class="
                    group.metrics.length <= 2
                        ? 'grid-cols-1 sm:grid-cols-2'
                        : 'grid-cols-2 sm:grid-cols-3 xl:grid-cols-5'
                "
            >
                <div
                    v-for="metric in group.metrics"
                    :key="metric.key"
                    :data-testid="`analytics-metric-${metric.key}`"
                    class="flex min-h-28 min-w-0 flex-col justify-between rounded-xl border-2 border-foreground bg-background px-4 py-4 shadow-xs"
                    :title="
                        metric.fact.time_basis
                            ? $t(
                                  `analytics.detail.time_basis.${metric.fact.time_basis}`,
                              )
                            : undefined
                    "
                >
                    <p class="text-sm font-medium text-foreground/70">
                        {{ label(metric.key) }}
                    </p>
                    <p
                        class="mt-3 text-2xl font-semibold tracking-tight break-words text-foreground tabular-nums"
                    >
                        {{ display(metric.key, metric.fact) }}
                    </p>
                    <p
                        v-if="
                            metric.fact.precision &&
                            metric.fact.precision !== 'exact'
                        "
                        class="text-[11px] text-muted-foreground"
                    >
                        {{ $t('analytics.detail.estimated') }}
                    </p>
                </div>
            </div>
        </AnalyticsSection>
    </div>
</template>
