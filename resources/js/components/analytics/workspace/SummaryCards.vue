<script setup lang="ts">
import { computed } from 'vue';

import { formatNumberCompact } from '@/lib/utils';

import AnalyticsSection from './AnalyticsSection.vue';
import type { WorkspaceAnalyticsReport } from './types';

const props = defineProps<{ report: WorkspaceAnalyticsReport }>();

const cards = computed(() => [
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

const display = (value: number | null, percent: boolean): string =>
    value === null ? '—' : percent ? `${value}%` : formatNumberCompact(value);

const changeLabel = (key: string, change: number | null): string | null => {
    if (change === null) return null;
    const prefix = change > 0 ? '+' : '';
    return `${prefix}${change}${key === 'followers' ? '' : '%'}`;
};
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.dashboard.summary')"
        :subtitle="$t('analytics.dashboard.latest_snapshot_hint')"
    >
        <div
            class="grid gap-2 sm:grid-cols-2 xl:grid-cols-5"
            data-testid="analytics-summary"
        >
            <div
                v-for="card in cards"
                :key="card.key"
                class="min-w-0 rounded-lg border border-border bg-background px-4 py-3"
            >
                <p class="text-xs text-muted-foreground">
                    {{ $t(card.label) }}
                </p>
                <div class="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span
                        class="text-2xl font-semibold text-foreground tabular-nums"
                        >{{ display(card.metric.value, card.percent) }}</span
                    >
                    <span
                        v-if="changeLabel(card.key, card.metric.change)"
                        class="text-xs font-medium tabular-nums"
                        :class="
                            card.metric.change! >= 0
                                ? 'text-emerald-700 dark:text-emerald-400'
                                : 'text-rose-700 dark:text-rose-400'
                        "
                    >
                        {{ changeLabel(card.key, card.metric.change) }}
                    </span>
                </div>
            </div>
        </div>
    </AnalyticsSection>
</template>
