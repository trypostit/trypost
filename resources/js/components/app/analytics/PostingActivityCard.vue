<script setup lang="ts">
import { transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import ShareChartButton from '@/components/app/analytics/ShareChartButton.vue';
import PostingActivityHeatmap from '@/components/app/analytics/workspace/charts/PostingActivityHeatmap.vue';
import InfoTip from '@/components/app/analytics/workspace/InfoTip.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { postingActivityShareOption } from '@/lib/app/insightsShare';
import {
    POSTING_ACTIVITY_STAT_ICONS,
    type PostingActivityStat,
} from '@/lib/app/postingActivity';
import type { ShareMetricOption } from '@/lib/app/shareCard';
import { blank, filled } from '@/lib/helpers';
import { formatFullNumber } from '@/lib/utils';
import type { PostingActivity } from '@/types/analytics';

const props = defineProps<{
    activity?: PostingActivity;
    /** The channel the activity belongs to, as the share card names it; the workspace when left out. */
    channel?: string;
}>();

const STATS: PostingActivityStat[] = ['current_streak', 'best_streak', 'total'];

const isLoading = computed((): boolean => blank(props.activity));

const days = (count: number): string =>
    transChoice('analytics.posting_activity.days', count);

/** The activity the share button shares: none while it loads, and none without a post to show. */
const shareable = computed((): PostingActivity | null => {
    if (!filled(props.activity)) {
        return null;
    }

    if (props.activity.total === 0) {
        return null;
    }

    return props.activity;
});

const statValue = (activity: PostingActivity, key: PostingActivityStat): string => {
    if (key === 'total') {
        return formatFullNumber(activity.total);
    }

    return days(activity[key]);
};

const labelKey = (key: PostingActivityStat): string => `analytics.posting_activity.${key}`;

const aboutKey = (key: PostingActivityStat): string =>
    `analytics.posting_activity.about.${key}`;

/** The share card's builder for the loaded activity. */
const shareBuilder = (activity: PostingActivity) => (): ShareMetricOption =>
    postingActivityShareOption(activity);
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.posting_activity.title')"
        :subtitle="$t('analytics.posting_activity.subtitle')"
        data-testid="posting-activity"
    >
        <template v-if="shareable" #actions>
            <ShareChartButton
                :subject="channel"
                :range="$t('analytics.posting_activity.subtitle')"
                :build="shareBuilder(shareable)"
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
                            :test-id="`posting-activity-${key}-about`"
                        />
                    </span>
                    <span class="flex items-center gap-1.5">
                        <component
                            :is="POSTING_ACTIVITY_STAT_ICONS[key].icon"
                            class="size-5 shrink-0"
                            :style="{ color: POSTING_ACTIVITY_STAT_ICONS[key].color }"
                            aria-hidden="true"
                        />
                        <span
                            class="font-heading text-2xl leading-8 font-medium whitespace-nowrap text-foreground tabular-nums"
                            :data-testid="`posting-activity-${key}`"
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
