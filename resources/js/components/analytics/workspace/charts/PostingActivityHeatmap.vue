<script setup lang="ts">
import {
    VisHeatmap,
    VisHeatmapSelectors,
    VisSingleContainer,
} from '@unovis/vue';
import { useElementSize } from '@vueuse/core';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';

import PostingActivityTooltip from '@/components/analytics/workspace/charts/PostingActivityTooltip.vue';
import {
    ChartContainer,
    ChartTooltip,
    componentToString,
    type ChartConfig,
} from '@/components/ui/chart';
import { useChartAppContext } from '@/composables/analytics/useChartAppContext';
import date from '@/date';
import { filled } from '@/lib/helpers';
import {
    DAYS_PER_WEEK,
    postingActivityMonths,
    postingActivityOffset,
} from '@/lib/postingActivity';
import { orderedWeekdays } from '@/preferences';
import type { PostingActivityDay } from '@/types/analytics';

const props = defineProps<{
    /** One entry per day, oldest first. */
    days: PostingActivityDay[];
    /** The busiest day's posts, the top of the colour scale. */
    maxCount: number;
}>();

const CELL_GAP = 4;
const MIN_CELL = 10;
/** Room the weekday labels take on the left: the chart's padding and the widest short weekday. */
const ROW_LABEL_SPACE = 36;
/** Room the month labels take on top: their font size and Unovis' label padding. */
const COLUMN_LABEL_SPACE = 17;
/** Monday, Wednesday and Friday (0 = Sunday), wherever the user's week starts. */
const LABELLED_WEEKDAYS = [1, 3, 5];
/** One violet at rising strength, so the scale reads the same in light and dark. */
const LEVEL_COLORS = [
    'color-mix(in oklab, var(--chart-3) 30%, transparent)',
    'color-mix(in oklab, var(--chart-3) 55%, transparent)',
    'color-mix(in oklab, var(--chart-3) 80%, transparent)',
    'var(--chart-3)',
];

/** A zero-height ruler: its width never depends on the grid, so measuring it cannot loop. */
const ruler = useTemplateRef<HTMLElement>('ruler');
const { width } = useElementSize(ruler);
const scroller = useTemplateRef<HTMLElement>('scroller');

const offset = computed((): number => postingActivityOffset(props.days));

const columns = computed((): number =>
    Math.ceil((props.days.length + offset.value) / DAYS_PER_WEEK),
);

/**
 * The cells stretch across the whole card; the chart's height keeps them square.
 * Below the smallest cell, a phone scrolls the grid instead of shrinking it.
 */
const cellSize = computed((): number => {
    const gaps = (columns.value - 1) * CELL_GAP;
    const fitting = (width.value - ROW_LABEL_SPACE - gaps) / columns.value;

    return Math.max(fitting, MIN_CELL);
});

const chartHeight = computed(
    (): number =>
        COLUMN_LABEL_SPACE +
        DAYS_PER_WEEK * cellSize.value +
        (DAYS_PER_WEEK - 1) * CELL_GAP,
);

const minChartWidth = computed(
    (): string =>
        `${ROW_LABEL_SPACE + columns.value * (MIN_CELL + CELL_GAP)}px`,
);

const colorDomain = computed((): [number, number] => {
    if (props.maxCount < 1) {
        return [1, 1];
    }

    return [1, props.maxCount];
});

/** A day without posts has no value, so it is drawn as an empty cell. */
const cellValue = (day: PostingActivityDay): number | null => {
    if (day.count === 0) {
        return null;
    }

    return day.count;
};

const months = computed(() => postingActivityMonths(props.days, offset.value));

/** The month's short name above the week its first day falls in. */
const columnLabel = (column: number): string | undefined =>
    months.value.find((month) => month.column === column)?.label;

const rowLabel = (row: number): string | undefined => {
    const weekday = orderedWeekdays()[row];

    if (!LABELLED_WEEKDAYS.includes(weekday)) {
        return undefined;
    }

    return date.formatShortWeekday(weekday);
};

/** The chart draws one series; the config only satisfies the container. */
const chartConfig: ChartConfig = {};

const appContext = useChartAppContext();
const tooltipTemplate = componentToString(
    chartConfig,
    PostingActivityTooltip,
    {},
    appContext,
);

const tooltipTriggers = {
    [VisHeatmapSelectors.cell]: (cell: {
        datum: PostingActivityDay;
    }): string | undefined => tooltipTemplate?.({ ...cell.datum }),
};

/** Whether the grid shows its latest weeks; false once the user scrolls back to older ones. */
const followsToday = ref(true);

/** A grid wider than a phone opens on the latest weeks, and keeps them in view as it resizes until the user scrolls away. */
const scrollToToday = async (): Promise<void> => {
    await nextTick();

    if (!filled(scroller.value)) {
        return;
    }

    if (!followsToday.value) {
        return;
    }

    scroller.value.scrollLeft = scroller.value.scrollWidth;
};

/** Remembers whether the user left the latest weeks, so a resize never pulls them back. */
const rememberScroll = (): void => {
    if (!filled(scroller.value)) {
        return;
    }

    const { scrollLeft, clientWidth, scrollWidth } = scroller.value;

    followsToday.value = Math.abs(scrollLeft) + clientWidth >= scrollWidth - 1;
};

watch(width, scrollToToday);

const cellAttributes = {
    [VisHeatmapSelectors.cell]: { 'data-testid': 'posting-activity-cell' },
};
</script>

<template>
    <div class="grid gap-3" data-testid="posting-activity-heatmap">
        <div ref="ruler" class="h-0 w-full" />
        <div
            ref="scroller"
            class="overflow-x-auto"
            role="img"
            @scroll="rememberScroll"
            :aria-label="$t('analytics.posting_activity.title')"
            data-testid="posting-activity-scroller"
        >
            <ChartContainer
                :config="chartConfig"
                class="[--vis-heatmap-cell-fill-color:var(--muted)] [--vis-heatmap-label-color:var(--muted-foreground)] [--vis-heatmap-label-font-size:11px] dark:[--vis-heatmap-cell-fill-color:var(--accent)]"
                :style="{ minWidth: minChartWidth }"
            >
                <VisSingleContainer
                    :data="days"
                    :height="chartHeight"
                    :padding="{ left: 6 }"
                >
                    <VisHeatmap
                        :value="cellValue"
                        :color-range="LEVEL_COLORS"
                        :color-domain="colorDomain"
                        :num-rows="DAYS_PER_WEEK"
                        layout="column"
                        :offset="offset"
                        :cell-padding="CELL_GAP"
                        :cell-corner-radius="3"
                        :column-label="columnLabel"
                        :row-label="rowLabel"
                        :attributes="cellAttributes"
                    />
                    <ChartTooltip :triggers="tooltipTriggers" />
                </VisSingleContainer>
            </ChartContainer>
        </div>

        <div
            class="flex items-center justify-center gap-1.5 text-xs text-muted-foreground"
        >
            <span>{{ $t('analytics.posting_activity.less') }}</span>
            <span class="size-3 rounded-[3px] bg-muted dark:bg-accent" />
            <span
                v-for="color in LEVEL_COLORS"
                :key="color"
                class="size-3 rounded-[3px]"
                :style="{ backgroundColor: color }"
            />
            <span>{{ $t('analytics.posting_activity.more') }}</span>
        </div>
    </div>
</template>
