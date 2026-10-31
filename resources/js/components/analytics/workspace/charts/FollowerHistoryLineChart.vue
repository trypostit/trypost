<script setup lang="ts">
import {
    VisAxis,
    VisAxisSelectors,
    VisLine,
    VisLineSelectors,
    VisScatter,
    VisScatterSelectors,
    VisXYContainer,
} from '@unovis/vue';
import { computed, getCurrentInstance, ref, useTemplateRef } from 'vue';

import {
    ChartContainer,
    ChartCrosshair,
    ChartTooltip,
    ChartTooltipContent,
    componentToString,
} from '@/components/ui/chart';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import date from '@/date';
import { accountColor } from '@/lib/analyticsColors';
import { formatNumberCompact } from '@/lib/utils';
import type {
    FollowerAccount,
    WorkspaceAnalyticsReport,
} from '@/types/analytics';

import LineEndLabel from './LineEndLabel.vue';
import {
    formatCountTick,
    socialAccountChartConfig,
} from './socialAccountChart';

type ChartPoint = {
    date: string;
    index: number;
    [key: string]: string | number | undefined;
};

const props = defineProps<{
    accounts: FollowerAccount[];
    series: WorkspaceAnalyticsReport['followers']['series'];
    colors: Record<string, string>;
}>();

const chartData = computed<ChartPoint[]>(() => {
    const lastKnown: (number | undefined)[] = [];

    return props.series.map((point, index) => {
        const values: ChartPoint = { date: point.date, index };

        props.accounts.forEach((account, accountIndex) => {
            lastKnown[accountIndex] =
                point.accounts[account.social_account_key] ??
                lastKnown[accountIndex];
            values[`account_${accountIndex}`] = lastKnown[accountIndex];
        });

        return values;
    });
});

const chartConfig = computed(() =>
    socialAccountChartConfig(props.accounts, props.colors),
);

const xAccessor = (point: ChartPoint): number => point.index;
const valueAccessor =
    (index: number) =>
    (point: ChartPoint): number | undefined => {
        const value = point[`account_${index}`];
        return typeof value === 'number' ? value : undefined;
    };
const formatDate = (tick: number | Date): string => {
    const index = typeof tick === 'number' ? Math.round(tick) : 0;
    const day = chartData.value[index]?.date;

    return day ? date.formatMonthDay(day) : '';
};
const appContext = getCurrentInstance()?.appContext ?? null;
const tooltipTemplate = computed(() =>
    componentToString(
        chartConfig.value,
        ChartTooltipContent,
        { labelFormatter: formatDate },
        appContext,
    ),
);
const lineColor = (index: number): string =>
    props.colors[props.accounts[index]?.social_account_key ?? ''] ??
    accountColor(index);

/** Room on the right for each line's avatar and latest number; narrower on a phone. */
const belowSm = useBelowBreakpoint('sm');
const railWidth = computed(() => (belowSm.value ? 92 : 112));
const chartPadding = computed(() => ({
    top: 16,
    right: railWidth.value,
    bottom: 8,
    left: 0,
}));
/** From the end of a line to its label, where a leader runs when the label had to move. */
const LEADER_LENGTH = 32;
/** The tallest a label is; labels closer than this are spread apart. */
const LABEL_HEIGHT = 24;

type LineEnd = {
    account: FollowerAccount;
    index: number;
    value: string;
    /** Where the line ends and where its label sits, in the frame's pixels. */
    lineY: number;
    labelY: number;
};

const frame = useTemplateRef<HTMLDivElement>('frame');
const lineEnds = ref<LineEnd[]>([]);
const lineEndX = ref(0);
/** The line whose label the pointer is on; the others fade. */
const focusedLine = ref<number | null>(null);

const focusLine = (index: number): void => {
    focusedLine.value = index;
};

const clearFocus = (): void => {
    focusedLine.value = null;
};

/** Whether a line fades while another channel's label is pointed at. */
const isDimmed = (index: number): boolean =>
    focusedLine.value !== null && focusedLine.value !== index;

const lineAttributes = (index: number) => ({
    [VisLineSelectors.line]: { 'data-dimmed': String(isDimmed(index)) },
});

const pointAttributes = (index: number) => ({
    [VisScatterSelectors.point]: { 'data-dimmed': String(isDimmed(index)) },
});

const valueTickAttributes = {
    [VisAxisSelectors.tick]: {
        'data-value-tick': (tick: number): string => String(tick),
    },
};

/** Each channel's latest known count, the value its line ends on. */
const latestValue = (index: number): number | undefined =>
    valueAccessor(index)(chartData.value[chartData.value.length - 1] ?? { date: '', index: 0 });

/** Maps a count to its height in the frame, read off two of the value axis' ticks. */
const valueToY = (
    svg: SVGSVGElement,
    top: number,
): ((value: number) => number) | null => {
    const ticks = [...svg.querySelectorAll<SVGGElement>('g[data-value-tick]')]
        .map((tick) => ({
            value: Number(tick.getAttribute('data-value-tick')),
            y: tick.getCTM()?.f,
        }))
        .filter((tick): tick is { value: number; y: number } => tick.y !== undefined);
    const [low, high] = [ticks[0], ticks[ticks.length - 1]];

    if (!low || !high || low.value === high.value) {
        return null;
    }

    return (value) => top + low.y + ((value - low.value) * (high.y - low.y)) / (high.value - low.value);
};

/** Spreads labels top to bottom so none overlaps the one above, then lifts them back inside `bottom`. */
const spreadLabels = (ends: LineEnd[], top: number, bottom: number): LineEnd[] => {
    const sorted = [...ends].sort((a, b) => a.lineY - b.lineY);
    const gap = Math.min(LABEL_HEIGHT, (bottom - top) / Math.max(sorted.length, 1));

    sorted.forEach((end, position) => {
        const above = sorted[position - 1];
        end.labelY = Math.max(end.lineY, top + gap / 2, above ? above.labelY + gap : -Infinity);
    });

    for (let position = sorted.length - 1; position >= 0; position -= 1) {
        const below = sorted[position + 1];
        sorted[position].labelY = Math.min(sorted[position].labelY, below ? below.labelY - gap : bottom - gap / 2);
    }

    return sorted;
};

const placeLineEnds = (svg: SVGSVGElement): void => {
    const box = frame.value?.getBoundingClientRect();

    if (!box) {
        return;
    }

    const svgBox = svg.getBoundingClientRect();
    const top = svgBox.top - box.top;
    const toY = valueToY(svg, top);

    if (!toY) {
        lineEnds.value = [];
        return;
    }

    lineEndX.value = svgBox.left - box.left + svgBox.width - railWidth.value;
    lineEnds.value = spreadLabels(
        props.accounts.flatMap((account, index) => {
            const value = latestValue(index);

            if (value === undefined) {
                return [];
            }

            return [{ account, index, value: formatNumberCompact(value), lineY: toY(value), labelY: 0 }];
        }),
        top,
        Math.min(toY(0), top + svgBox.height),
    );
};

/** A leader only where a label had to move away from its line. */
const movedEnds = computed(() =>
    lineEnds.value.filter((end) => Math.abs(end.labelY - end.lineY) > 1),
);
const crosshairColor = (_point: ChartPoint, index: number): string =>
    lineColor(Math.floor(index / 2));
</script>

<template>
    <div
        ref="frame"
        class="relative"
        role="img"
        :aria-label="$t('analytics.dashboard.followers_line_description')"
    >
        <ChartContainer
            :config="chartConfig"
            cursor
            class="h-72 w-full sm:h-80 [&_[data-dimmed=true]]:opacity-20 [&_[data-dimmed]]:transition-opacity"
            data-testid="followers-unovis-chart"
        >
            <VisXYContainer
                :data="chartData"
                :padding="chartPadding"
                :on-render-complete="placeLineEnds"
            >
                <template
                    v-for="(account, index) in accounts"
                    :key="account.social_account_key"
                >
                    <VisLine
                        :x="xAccessor"
                        :y="valueAccessor(index)"
                        :color="lineColor(index)"
                        :line-width="2"
                        curve-type="monotoneX"
                        :attributes="lineAttributes(index)"
                    />
                    <VisScatter
                        :x="xAccessor"
                        :y="valueAccessor(index)"
                        :color="lineColor(index)"
                        :size="4"
                        :attributes="pointAttributes(index)"
                    />
                </template>
                <VisAxis
                    type="x"
                    :tick-format="formatDate"
                    :num-ticks="Math.min(chartData.length, 6)"
                    :grid-line="false"
                    :domain-line="false"
                    :tick-line="false"
                />
                <VisAxis
                    type="y"
                    :tick-format="formatCountTick"
                    :num-ticks="5"
                    :grid-line="true"
                    :domain-line="false"
                    :tick-line="false"
                    :attributes="valueTickAttributes"
                />
                <ChartCrosshair
                    :template="tooltipTemplate"
                    :color="crosshairColor"
                    :circleRadius="5"
                    strokeColor="var(--card)"
                    :strokeWidth="2"
                />
                <ChartTooltip />
            </VisXYContainer>
        </ChartContainer>
        <svg
            class="pointer-events-none absolute inset-0 size-full overflow-visible"
            aria-hidden="true"
        >
            <polyline
                v-for="end in movedEnds"
                :key="end.account.social_account_key"
                :points="`${lineEndX + 4},${end.lineY} ${lineEndX + LEADER_LENGTH / 2},${end.lineY} ${lineEndX + LEADER_LENGTH - 2},${end.labelY}`"
                fill="none"
                :stroke="lineColor(end.index)"
                stroke-width="1.5"
                stroke-linejoin="round"
                class="transition-opacity data-[dimmed=true]:opacity-20"
                :data-dimmed="isDimmed(end.index)"
            />
        </svg>
        <div
            v-for="end in lineEnds"
            :key="end.account.social_account_key"
            class="absolute z-10 -translate-y-1/2"
            :style="{ left: `${lineEndX + LEADER_LENGTH}px`, top: `${end.labelY}px` }"
            data-testid="followers-line-end"
            :data-account="end.account.social_account_key"
            @mouseenter="focusLine(end.index)"
            @mouseleave="clearFocus"
        >
            <LineEndLabel :account="end.account" :value="end.value" />
        </div>
    </div>
</template>
