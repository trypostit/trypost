<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    IconActivity,
    IconBookmark,
    IconChartBar,
    IconChevronLeft,
    IconChevronRight,
    IconClick,
    IconClock,
    IconClockPlay,
    IconExternalLink,
    IconEye,
    IconMessage,
    IconMessageReply,
    IconPercentage,
    IconPinned,
    IconQuote,
    IconRepeat,
    IconTarget,
    IconThumbUp,
    IconTrendingUp,
    IconUserPlus,
} from '@tabler/icons-vue';
import { useResizeObserver } from '@vueuse/core';
import { computed, type HTMLAttributes, ref } from 'vue';

import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import date from '@/date';
import {
    compactPublicationMetrics,
    formatPublicationMetric,
    publicationMetricLabel,
} from '@/lib/publicationMetrics';
import { cn } from '@/lib/utils';
import { insights as channelInsights } from '@/routes/app/channels';
import type { PublicationAnalyticsDetail } from '@/types/analytics';

const props = withDefaults(
    defineProps<{
        detail: PublicationAnalyticsDetail;
        channelId: string | null;
        insightsTestId?: string;
        metricsTestId?: string;
        class?: HTMLAttributes['class'];
    }>(),
    {
        insightsTestId: undefined,
        metricsTestId: undefined,
        class: undefined,
    },
);

const METRIC_ICONS: Record<string, typeof IconEye> = {
    views: IconEye,
    video_views: IconEye,
    engaged_views: IconEye,
    impressions: IconEye,
    reach: IconTarget,
    reactions: IconThumbUp,
    comments: IconMessage,
    replies: IconMessageReply,
    shares: IconRepeat,
    reposts: IconRepeat,
    quotes: IconQuote,
    saves: IconBookmark,
    bookmarks: IconBookmark,
    engagement_rate: IconActivity,
    follows: IconTrendingUp,
    subscribers_gained: IconUserPlus,
    watch_time_milliseconds: IconClockPlay,
    average_watch_time_milliseconds: IconClockPlay,
    total_play_time_milliseconds: IconClockPlay,
    average_video_play_time_milliseconds: IconClockPlay,
    average_percentage_viewed: IconPercentage,
    clicks: IconClick,
    link_clicks: IconClick,
    pin_clicks: IconPinned,
    outbound_clicks: IconExternalLink,
    save_rate: IconBookmark,
};

const metrics = computed(() => compactPublicationMetrics(props.detail));

const refreshedAt = computed(() => props.detail.snapshot?.collected_at ?? null);

const insightsUrl = computed(() =>
    props.channelId ? channelInsights.url(props.channelId) : null,
);

const scroller = ref<HTMLElement | null>(null);
const metricItems = ref<HTMLElement[]>([]);
const canScrollPrev = ref(false);
const canScrollNext = ref(false);

const isRtl = (element: HTMLElement): boolean =>
    getComputedStyle(element).direction === 'rtl';

const updateArrows = (): void => {
    const element = scroller.value;

    if (!element) {
        canScrollPrev.value = false;
        canScrollNext.value = false;

        return;
    }

    const offset = Math.abs(element.scrollLeft);

    canScrollPrev.value = offset > 1;
    canScrollNext.value = offset + element.clientWidth < element.scrollWidth - 1;
};

const scrollMetrics = (direction: 1 | -1): void => {
    const element = scroller.value;

    if (!element) {
        return;
    }

    const step = element.clientWidth * 0.8 * direction;

    element.scrollBy({ left: isRtl(element) ? -step : step, behavior: 'smooth' });
};

useResizeObserver(
    () => [scroller.value, ...metricItems.value],
    updateArrows,
);
</script>

<template>
    <div
        :class="
            cn(
                'group/metrics relative flex items-center gap-2 border-t border-border-strong px-4 py-2',
                props.class,
            )
        "
    >
        <span
            v-if="refreshedAt"
            class="pointer-events-none absolute -top-3 end-4 z-10 hidden items-center gap-1 rounded-full border border-border-strong bg-card px-2 py-0.5 text-xs whitespace-nowrap text-muted-foreground shadow-xs group-hover/metrics:inline-flex"
            :data-testid="metricsTestId ? `${metricsTestId}-refreshed` : undefined"
        >
            <IconClock class="size-3.5" aria-hidden="true" />
            {{
                $t('posts.publish.metrics.refreshed', {
                    time: date.diffForHumans(refreshedAt),
                })
            }}
        </span>
        <div v-if="metrics.length" class="relative flex min-w-0 flex-1 items-center">
            <div
                v-if="canScrollPrev"
                class="pointer-events-none absolute inset-y-0 start-0 z-[5] w-16 bg-gradient-to-r from-card via-card/80 to-transparent rtl:bg-gradient-to-l"
                aria-hidden="true"
                :data-testid="metricsTestId ? `${metricsTestId}-fade-prev` : undefined"
            />
            <Button
                v-if="canScrollPrev"
                variant="outline"
                size="icon"
                class="absolute start-0 z-10 size-6 rounded-full shadow-xs"
                :aria-label="$t('posts.publish.metrics.previous')"
                :data-testid="metricsTestId ? `${metricsTestId}-prev` : undefined"
                @click="scrollMetrics(-1)"
            >
                <IconChevronLeft class="size-3.5 rtl:rotate-180" />
            </Button>
            <dl
                ref="scroller"
                class="flex min-w-0 flex-1 gap-8 overflow-x-auto py-3 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                :data-testid="metricsTestId"
                @scroll.passive="updateArrows"
            >
                <div
                    v-for="metric in metrics"
                    :key="metric.key"
                    ref="metricItems"
                    class="flex shrink-0 flex-col gap-1"
                    :data-metric="metric.key"
                >
                    <dt
                        class="inline-flex items-center gap-1 text-sm font-medium whitespace-nowrap text-foreground"
                    >
                        <component
                            :is="METRIC_ICONS[metric.key] ?? IconChartBar"
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        {{
                            publicationMetricLabel(
                                metric.key,
                                detail.publication?.platform,
                            )
                        }}
                    </dt>
                    <dd class="text-sm leading-none font-strong tabular-nums">
                        {{ formatPublicationMetric(metric.key, metric.fact) }}
                    </dd>
                </div>
            </dl>
            <div
                v-if="canScrollNext"
                class="pointer-events-none absolute inset-y-0 end-0 z-[5] w-16 bg-gradient-to-l from-card via-card/80 to-transparent rtl:bg-gradient-to-r"
                aria-hidden="true"
                :data-testid="metricsTestId ? `${metricsTestId}-fade-next` : undefined"
            />
            <Button
                v-if="canScrollNext"
                variant="outline"
                size="icon"
                class="absolute end-0 z-10 size-6 rounded-full shadow-xs"
                :aria-label="$t('posts.publish.metrics.next')"
                :data-testid="metricsTestId ? `${metricsTestId}-next` : undefined"
                @click="scrollMetrics(1)"
            >
                <IconChevronRight class="size-3.5 rtl:rotate-180" />
            </Button>
        </div>
        <p v-else class="min-w-0 flex-1 py-3 text-sm text-muted-foreground">
            {{ $t('analytics.detail.awaiting_metrics') }}
        </p>
        <Tooltip v-if="insightsUrl">
            <TooltipTrigger as-child>
                <Button as-child variant="outline" size="icon" class="shrink-0">
                    <Link
                        :href="insightsUrl"
                        :aria-label="$t('posts.publish.actions.see_insights')"
                        :data-testid="insightsTestId"
                    >
                        <IconChartBar class="size-4" />
                    </Link>
                </Button>
            </TooltipTrigger>
            <TooltipContent>
                {{ $t('posts.publish.actions.see_insights') }}
            </TooltipContent>
        </Tooltip>
    </div>
</template>
