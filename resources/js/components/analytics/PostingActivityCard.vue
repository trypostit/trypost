<script setup lang="ts">
import { IconFlame } from '@tabler/icons-vue';
import { transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

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

/** The flame marks a streak that is still alive. */
const showsFlame = (activity: PostingActivity, key: StatKey): boolean => {
    if (key !== 'current_streak') {
        return false;
    }

    return activity.current_streak > 0;
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
            <div class="flex items-start gap-4 max-sm:flex-1">
                <dl
                    class="flex flex-wrap items-start gap-x-4 gap-y-2 max-sm:me-auto max-sm:min-w-0 max-sm:flex-1 sm:gap-x-6"
                >
                    <div v-for="key in STATS" :key="key" class="grid gap-0.5">
                        <dt
                            class="flex items-center gap-0.5 text-xs whitespace-nowrap text-muted-foreground"
                        >
                            {{ $t(labelKey(key)) }}
                            <InfoTip
                                :text="$t(aboutKey(key))"
                                :test-id="`${testId(key)}-about`"
                            />
                        </dt>
                        <dd class="flex items-center gap-1">
                            <IconFlame
                                v-if="showsFlame(activity, key)"
                                class="size-4 shrink-0 text-orange-500"
                                aria-hidden="true"
                            />
                            <span
                                class="font-heading text-lg leading-6 font-medium whitespace-nowrap text-foreground tabular-nums"
                                :data-testid="testId(key)"
                                >{{ statValue(activity, key) }}</span
                            >
                        </dd>
                    </div>
                </dl>
                <ShareChartButton
                    :subject="channel"
                    :range="$t('analytics.posting_activity.subtitle')"
                    :build="shareBuilder(activity)"
                    test-id="share-posting-activity"
                />
            </div>
        </template>
        <div
            v-if="isLoading"
            class="grid gap-4"
            data-testid="posting-activity-skeleton"
            aria-busy="true"
        >
            <Skeleton class="h-40 w-full" />
        </div>

        <PostingActivityHeatmap
            v-else-if="activity"
            :days="activity.days"
            :max-count="activity.max_count"
        />
    </AnalyticsSection>
</template>
