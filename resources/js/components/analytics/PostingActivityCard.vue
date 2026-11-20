<script setup lang="ts">
import {
    IconCrownFilled,
    IconFlameFilled,
    IconSendFilled,
} from '@tabler/icons-vue';
import { transChoice } from 'laravel-vue-i18n';
import { computed, type Component } from 'vue';

import ShareChartButton from '@/components/analytics/ShareChartButton.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import PostingActivityHeatmap from '@/components/analytics/workspace/charts/PostingActivityHeatmap.vue';
import InfoTip from '@/components/analytics/workspace/InfoTip.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { blank } from '@/lib/helpers';
import { postingActivityShareOption } from '@/lib/insightsShare';
import type { ShareMetricOption } from '@/lib/shareCard';
import { formatFullNumber } from '@/lib/utils';
import type { PostingActivity } from '@/types/analytics';

type StatKey = 'current_streak' | 'best_streak' | 'total';

const props = defineProps<{
    activity?: PostingActivity;
    /** The channel the activity belongs to, as the share card names it; the workspace when left out. */
    channel?: string;
}>();

const STATS: StatKey[] = ['current_streak', 'best_streak', 'total'];

/** Each number's icon and color, in place of the series swatch the Metrics card's legend draws. */
const STAT_ICONS: Record<StatKey, { icon: Component; color: string }> = {
    current_streak: { icon: IconFlameFilled, color: 'text-orange-500' },
    best_streak: { icon: IconCrownFilled, color: 'text-amber-400' },
    total: { icon: IconSendFilled, color: 'text-chart-3' },
};

const isLoading = computed((): boolean => blank(props.activity));

const days = (count: number): string =>
    transChoice('analytics.posting_activity.days', count, {
        count: formatFullNumber(count),
    });

const statValue = (activity: PostingActivity, key: StatKey): string => {
    if (key === 'total') {
        return formatFullNumber(activity.total);
    }

    return days(activity[key]);
};

const labelKey = (key: StatKey): string => `analytics.posting_activity.${key}`;

const aboutKey = (key: StatKey): string =>
    `analytics.posting_activity.about.${key}`;

/** The share card's builder for the loaded activity. */
const shareBuilder = (activity: PostingActivity) => (): ShareMetricOption =>
    postingActivityShareOption(activity);

const testId = (key: StatKey): string =>
    `posting-activity-${key.replace('_', '-')}`;
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.posting_activity.title')"
        :subtitle="$t('analytics.posting_activity.subtitle')"
        data-testid="posting-activity"
    >
        <template v-if="activity" #actions>
            <ShareChartButton
                :subject="channel"
                :range="$t('analytics.posting_activity.subtitle')"
                :build="shareBuilder(activity)"
                test-id="share-posting-activity"
            />
        </template>
        <div
            v-if="isLoading"
            class="grid gap-5"
            data-testid="posting-activity-skeleton"
            aria-busy="true"
        >
            <Skeleton class="h-9 w-2/3" />
            <Skeleton class="h-40 w-full" />
        </div>

        <div v-else-if="activity" class="min-w-0">
            <div
                class="mb-5 flex flex-wrap gap-x-8 gap-y-3"
                data-testid="posting-activity-stats"
            >
                <div
                    v-for="key in STATS"
                    :key="key"
                    class="flex min-w-0 flex-col gap-1"
                >
                    <span
                        class="flex items-center gap-0.5 text-sm whitespace-nowrap text-muted-foreground"
                    >
                        {{ $t(labelKey(key)) }}
                        <InfoTip
                            :text="$t(aboutKey(key))"
                            :test-id="`${testId(key)}-about`"
                        />
                    </span>
                    <span class="flex items-center gap-1.5">
                        <component
                            :is="STAT_ICONS[key].icon"
                            class="size-5 shrink-0"
                            :class="STAT_ICONS[key].color"
                            aria-hidden="true"
                        />
                        <span
                            class="font-heading text-2xl leading-8 font-medium whitespace-nowrap text-foreground tabular-nums"
                            :data-testid="testId(key)"
                            >{{ statValue(activity, key) }}</span
                        >
                    </span>
                </div>
            </div>
            <PostingActivityHeatmap
                :days="activity.days"
                :max-count="activity.max_count"
            />
        </div>
    </AnalyticsSection>
</template>
