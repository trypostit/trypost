<script setup lang="ts">
import { IconFlame } from '@tabler/icons-vue';
import { transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

import ShareChartButton from '@/components/analytics/ShareChartButton.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import PostingActivityHeatmap from '@/components/analytics/workspace/charts/PostingActivityHeatmap.vue';
import InfoTip from '@/components/analytics/workspace/InfoTip.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { activeLocale } from '@/language';
import { blank } from '@/lib/helpers';
import { postingActivityShareOption } from '@/lib/insightsShare';
import type { ShareMetricOption } from '@/lib/shareCard';
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
        count: count.toLocaleString(activeLocale.value),
    });

const statValue = (activity: PostingActivity, key: StatKey): string => {
    if (key === 'total') {
        return activity.total.toLocaleString(activeLocale.value);
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
        flush
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
            class="grid gap-4 border-t border-border px-4 py-4 sm:px-5"
            data-testid="posting-activity-skeleton"
            aria-busy="true"
        >
            <div class="grid gap-4 sm:grid-cols-3">
                <Skeleton class="h-14" />
                <Skeleton class="h-14" />
                <Skeleton class="h-14" />
            </div>
            <Skeleton class="h-40 w-full" />
        </div>

        <template v-else-if="activity">
            <div class="grid border-t border-border sm:grid-cols-3">
                <div
                    v-for="key in STATS"
                    :key="key"
                    class="flex min-w-0 flex-col gap-2 px-4 py-4 shadow-[1px_0_0_var(--border),0_1px_0_var(--border)] sm:px-5"
                >
                    <div class="flex min-h-6 items-center gap-0.5">
                        <p class="truncate text-sm text-muted-foreground">
                            {{ $t(labelKey(key)) }}
                        </p>
                        <InfoTip
                            :text="$t(aboutKey(key))"
                            :test-id="`${testId(key)}-about`"
                        />
                    </div>
                    <div class="flex items-center gap-1.5">
                        <IconFlame
                            v-if="showsFlame(activity, key)"
                            class="size-5 shrink-0 text-orange-500"
                            aria-hidden="true"
                        />
                        <span
                            class="font-heading text-2xl leading-8 font-medium whitespace-nowrap text-foreground tabular-nums"
                            :data-testid="testId(key)"
                            >{{ statValue(activity, key) }}</span
                        >
                    </div>
                </div>
            </div>

            <div class="px-4 py-4 sm:px-5 sm:py-5">
                <PostingActivityHeatmap
                    :days="activity.days"
                    :max-count="activity.max_count"
                />
            </div>
        </template>
    </AnalyticsSection>
</template>
