<script setup lang="ts">
import { computed } from 'vue';

import {
    formatNumberCompact,
    formatPercent,
    formatPercentChange,
} from '@/lib/utils';

import type { WorkspaceAnalyticsReport } from '@/types/analytics';
import AnalyticsSection from './AnalyticsSection.vue';

const props = defineProps<{ report: WorkspaceAnalyticsReport }>();

const cards = computed(() => [
    {
        key: 'followers',
        label: 'analytics.dashboard.total_followers',
        metric: props.report.summary.followers,
        percent: false,
    },
    {
        key: 'posts',
        label: 'analytics.dashboard.posts',
        metric: props.report.summary.posts,
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

const display = (value: number | null, percent: boolean): string =>
    value === null
        ? '—'
        : percent
          ? formatPercent(value)
          : formatNumberCompact(value);

const changeLabel = (key: string, change: number | null): string | null => {
    if (change === null) return null;
    return key === 'followers'
        ? `${change > 0 ? '+' : ''}${formatNumberCompact(change)}`
        : formatPercentChange(change);
};
</script>

<template>
    <AnalyticsSection :title="$t('analytics.dashboard.summary')">
        <div
            class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5"
            data-testid="analytics-summary"
        >
            <div
                v-for="card in cards"
                :key="card.key"
                :data-testid="`analytics-summary-${card.key}`"
                class="flex min-h-28 min-w-0 flex-col justify-between rounded-xl border-2 border-foreground px-4 py-4 shadow-xs"
                :class="
                    card.key === 'followers'
                        ? 'bg-violet-100 text-foreground sm:col-span-2 lg:col-span-1'
                        : 'bg-background text-foreground'
                "
            >
                <p class="text-sm font-medium text-foreground/70">
                    {{ $t(card.label) }}
                </p>
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span
                        class="text-3xl font-semibold tracking-tight text-foreground tabular-nums"
                        >{{ display(card.metric.value, card.percent) }}</span
                    >
                    <span
                        v-if="changeLabel(card.key, card.metric.change)"
                        :data-testid="`analytics-summary-${card.key}-change`"
                        class="rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums"
                        :class="
                            card.metric.change! >= 0
                                ? 'bg-emerald-50 text-emerald-700'
                                : 'bg-rose-50 text-rose-700'
                        "
                    >
                        {{ card.metric.change! >= 0 ? '↗' : '↘' }}
                        {{ changeLabel(card.key, card.metric.change) }}
                    </span>
                </div>
            </div>
        </div>
        <p class="mt-3 text-xs text-muted-foreground">
            {{ $t('analytics.dashboard.latest_snapshot_hint') }}
        </p>
    </AnalyticsSection>
</template>
