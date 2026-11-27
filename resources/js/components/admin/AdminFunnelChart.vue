<script setup lang="ts">
import { IconArrowRight } from '@tabler/icons-vue';
import { useElementSize } from '@vueuse/core';
import { computed, ref, useId, useTemplateRef } from 'vue';

import { buildFunnelColumnPaths, funnelShade } from '@/lib/funnelRibbon';
import { blank, clamp, filled } from '@/lib/helpers';
import { formatFullNumber, formatPercent, percentOf } from '@/lib/utils';

export interface AdminFunnelStep {
    key: string;
    label: string;
    count: number;
}

/**
 * A funnel drawn as a ribbon: one column per step, narrowing from each
 * step's count to the next one's, the share lost between two steps in a
 * pill on their border, and each step's count and share of the first under it.
 */
const props = defineProps<{
    steps: AdminFunnelStep[];
    testId?: string;
}>();

/** Below this a column scrolls instead of squeezing. */
const COLUMN_MIN_WIDTH = 112;
const RIBBON_HEIGHT = 224;
/** Room over and under the widest band. */
const PADDING = 28;
/** A step with anyone in it never shrinks to nothing. */
const MIN_VISIBLE_RATIO = 0.02;
const EDGE_STROKE_WIDTH = 10;
const EDGE_STROKE_OPACITY = 0.5;
/** The other columns fade while one is pointed at. */
const DIMMED_OPACITY = 0.45;
/** A step that loses at least this share of the one before is the drop to look at. */
const BIG_DROP = 50;

/** A soft white sheen over the top of the ribbon, so it reads as a shape and not a flat band. */
const sheenId = `funnel-sheen-${useId()}`;
const sheenFill = `url(#${sheenId})`;

const viewport = useTemplateRef<HTMLElement>('viewport');
const { width: viewportWidth } = useElementSize(viewport);

const contentWidth = computed((): number =>
    Math.max(viewportWidth.value, props.steps.length * COLUMN_MIN_WIDTH, 1),
);

const columnWidth = computed((): number => contentWidth.value / Math.max(props.steps.length, 1));

const centerY = RIBBON_HEIGHT / 2;
const maxHalfHeight = centerY - PADDING;

/** The first step's count, the one every share is of; never below 1, so an empty funnel draws nothing. */
const first = computed((): number => {
    const [firstStep] = props.steps;

    if (filled(firstStep)) {
        return Math.max(firstStep.count, 1);
    }

    return 1;
});

const halfHeight = (count: number): number => {
    if (count <= 0) {
        return 0;
    }

    return clamp(count / first.value, MIN_VISIBLE_RATIO, 1) * maxHalfHeight;
};

/** Where a column ends: at the next step's height, or flat when it is the last. */
const rightHalfHeight = (index: number, halfLeft: number): number => {
    const next = props.steps[index + 1];

    if (filled(next)) {
        return halfHeight(next.count);
    }

    return halfLeft;
};

const columns = computed(() =>
    props.steps.map((step, index) => {
        const halfLeft = halfHeight(step.count);
        const halfRight = rightHalfHeight(index, halfLeft);
        const x0 = index * columnWidth.value;

        return {
            ...buildFunnelColumnPaths(x0, columnWidth.value, centerY, halfLeft, halfRight),
            color: funnelShade(index, props.steps.length),
            x: x0,
        };
    }),
);

/**
 * The share of the step before that did not go on, to one decimal. A step
 * that lost nobody reads 0%, never −0%; the steps are counted on their own,
 * so a step bigger than the one before reads as a gain.
 */
const lostLabel = (lost: number): string => {
    if (Math.round(lost * 10) === 0) {
        return formatPercent(0, 1);
    }

    if (lost < 0) {
        return `+${formatPercent(-lost, 1)}`;
    }

    return `−${formatPercent(lost, 1)}`;
};

const pillClass = (lost: number): string => {
    if (lost >= BIG_DROP) {
        return 'border-destructive/50 bg-background text-destructive-text font-semibold';
    }

    return 'border-border bg-background text-muted-foreground';
};

/** Where each step hands over to the next, and the share it lost on the way. */
const boundaries = computed(() =>
    props.steps.slice(1).map((step, index) => {
        const previous = props.steps[index].count;
        const lost = percentOf(previous - step.count, previous);

        return {
            left: `${((index + 1) / props.steps.length) * 100}%`,
            label: lostLabel(lost),
            pillClass: pillClass(lost),
        };
    }),
);

const ofFirst = (count: number): string => `${formatPercent(percentOf(count, first.value), 1)} of signups`;

const shade = (index: number): string => funnelShade(index, props.steps.length);

const gridStyle = computed(() => ({
    width: `${contentWidth.value}px`,
    gridTemplateColumns: `repeat(${props.steps.length}, minmax(0, 1fr))`,
}));

const hovered = ref<number | null>(null);

const hover = (index: number): void => {
    hovered.value = index;
};

const leave = (): void => {
    hovered.value = null;
};

const opacity = (index: number, base: number): number => {
    if (blank(hovered.value)) {
        return base;
    }

    if (hovered.value === index) {
        return base;
    }

    return base * DIMMED_OPACITY;
};

const contentStyle = computed(() => ({ width: `${contentWidth.value}px` }));

const RIBBON_STYLE = { height: `${RIBBON_HEIGHT}px` };

const viewBox = computed((): string => `0 0 ${contentWidth.value} ${RIBBON_HEIGHT}`);
</script>

<template>
    <div ref="viewport" class="overflow-x-auto" :data-testid="testId" @mouseleave="leave">
        <div :style="contentStyle">
            <div class="relative" :style="RIBBON_STYLE">
                <svg
                    :viewBox="viewBox"
                    preserveAspectRatio="none"
                    class="block h-full w-full overflow-visible"
                    role="img"
                    aria-label="Funnel"
                >
                    <defs>
                        <linearGradient :id="sheenId" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="white" stop-opacity="0.28" />
                            <stop offset="50%" stop-color="white" stop-opacity="0.06" />
                            <stop offset="100%" stop-color="white" stop-opacity="0" />
                        </linearGradient>
                    </defs>
                    <path
                        v-for="(column, index) in columns"
                        :key="`edge-${index}`"
                        :d="column.edgePath"
                        fill="none"
                        :stroke="column.color"
                        :stroke-opacity="opacity(index, EDGE_STROKE_OPACITY)"
                        :stroke-width="EDGE_STROKE_WIDTH"
                        stroke-linejoin="round"
                        stroke-linecap="round"
                    />
                    <path
                        v-for="(column, index) in columns"
                        :key="`fill-${index}`"
                        :d="column.path"
                        :fill="column.color"
                        :fill-opacity="opacity(index, 1)"
                        class="transition-[fill-opacity] duration-150"
                    />
                    <path
                        v-for="(column, index) in columns"
                        :key="`sheen-${index}`"
                        :d="column.path"
                        :fill="sheenFill"
                        :fill-opacity="opacity(index, 1)"
                        class="pointer-events-none"
                    />
                    <rect
                        v-for="(column, index) in columns"
                        :key="`hit-${index}`"
                        :x="column.x"
                        y="0"
                        :width="columnWidth"
                        :height="RIBBON_HEIGHT"
                        fill="transparent"
                        @mouseenter="hover(index)"
                    />
                </svg>

                <template v-for="(boundary, index) in boundaries" :key="index">
                    <span
                        class="pointer-events-none absolute inset-y-2 border-l border-dashed border-border"
                        :style="{ left: boundary.left }"
                        aria-hidden="true"
                    />
                    <span
                        class="pointer-events-none absolute top-1/2 flex -translate-x-1/2 -translate-y-1/2 items-center gap-1 rounded-full border px-2 py-0.5 text-xs whitespace-nowrap tabular-nums shadow-sm"
                        :class="boundary.pillClass"
                        :style="{ left: boundary.left }"
                    >
                        {{ boundary.label }}
                        <IconArrowRight class="size-3" aria-hidden="true" />
                    </span>
                </template>
            </div>

            <div class="mt-3 grid" :style="gridStyle">
                <div
                    v-for="(step, index) in steps"
                    :key="step.key"
                    class="flex min-w-0 flex-col gap-0.5 pe-3"
                    :data-testid="`funnel-step-${step.key}`"
                    @mouseenter="hover(index)"
                >
                    <span class="flex min-h-8 min-w-0 items-start gap-1.5">
                        <span
                            class="mt-1 size-2 shrink-0 rounded-full"
                            :style="{ backgroundColor: shade(index) }"
                            aria-hidden="true"
                        />
                        <span class="text-xs leading-4 text-muted-foreground">{{ step.label }}</span>
                    </span>
                    <span class="font-heading text-xl leading-7 font-medium text-foreground tabular-nums">{{
                        formatFullNumber(step.count)
                    }}</span>
                    <span class="truncate text-xs text-muted-foreground tabular-nums">{{ ofFirst(step.count) }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
