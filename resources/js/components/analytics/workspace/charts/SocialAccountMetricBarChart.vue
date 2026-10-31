<script setup lang="ts">
import {
    VisAxis,
    VisAxisSelectors,
    VisStackedBar,
    VisStackedBarSelectors,
    VisXYContainer,
} from '@unovis/vue';
import {
    computed,
    getCurrentInstance,
    h,
    onBeforeUnmount,
    render,
    useTemplateRef,
} from 'vue';

import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    type ChartTooltipDetail,
    componentToString,
} from '@/components/ui/chart';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import { accountColor } from '@/lib/analyticsColors';
import { formatNumberCompact } from '@/lib/utils';
import type { AccountIdentityData } from '@/types/analytics';

import AxisChannelRow from './AxisChannelRow.vue';
import {
    formatCountTick,
    socialAccountChartConfig,
} from './socialAccountChart';

const props = withDefaults(
    defineProps<{
        rows: {
            account: AccountIdentityData;
            value: number | null;
        }[];
        colors: Record<string, string>;
        detail?: (index: number) => ChartTooltipDetail[];
        /** How each channel's number reads in the column before its bar. */
        formatValue?: (value: number) => string;
    }>(),
    { detail: undefined, formatValue: formatNumberCompact },
);

type BarPoint = { index: number; value: number };
const chartData = computed<BarPoint[]>(() =>
    props.rows.map((row, index) => ({ index, value: row.value ?? 0 })),
);
const chartConfig = computed(() =>
    socialAccountChartConfig(
        props.rows.map((row) => row.account),
        props.colors,
    ),
);
const chartHeight = computed(
    () => `${Math.max(160, props.rows.length * 37 + 60)}px`,
);
const indexAccessor = (point: BarPoint): number => point.index;
const valueAccessor = (point: BarPoint): number => point.value;
const rowColor = (point: BarPoint): string => {
    const key = props.rows[point.index]?.account.social_account_key;
    return (key && props.colors[key]) || accountColor(point.index);
};
const rowAt = (index: number) => props.rows[Math.round(index)];
/** The axis draws no text: each row's channel and number are laid over it as HTML. */
const blankTick = (): string => '';
const rowValue = (value: number | null): string => {
    if (value === null) {
        return '—';
    }

    return props.formatValue(value);
};
const categoryTicks = computed(() => props.rows.map((_, index) => index));
const detailFormatter = (key: string): ChartTooltipDetail[] =>
    props.detail?.(Number(key.replace('account_', ''))) ?? [];
const tooltipTemplate = computed(() =>
    componentToString(
        chartConfig.value,
        ChartTooltipContent,
        { detailFormatter },
        appContext,
    ),
);
const tooltipTriggers = computed(() => ({
    [VisStackedBarSelectors.bar]: (bar: {
        datum: BarPoint;
    }): string | undefined => {
        const point = bar.datum;
        return tooltipTemplate.value?.({
            [`account_${point.index}`]: props.rows[point.index]?.value,
        });
    },
}));
const tickAttributes = {
    [VisAxisSelectors.tick]: {
        'data-account-tick': (tick: number): string => String(Math.round(tick)),
    },
};
const AVATAR_SIZE = 24;
/** Between a row's number and the start of its bar. */
const COLUMN_GAP = 12;
/** Avatar, name and number before the bars; narrower on a phone so the bars keep room. */
const belowSm = useBelowBreakpoint('sm');
const columnWidth = computed(() => (belowSm.value ? 176 : 240));
/** The bars start after the rows' column; the axis itself draws no text to make room for. */
const chartPadding = computed(() => ({
    top: 12,
    right: 16,
    bottom: 0,
    left: columnWidth.value + COLUMN_GAP,
}));
const appContext = getCurrentInstance()?.appContext ?? null;
const avatarHosts: HTMLElement[] = [];
const avatarLayer = useTemplateRef<HTMLDivElement>('avatarLayer');

const unmountAvatars = (): void => {
    avatarHosts.splice(0).forEach((host) => {
        render(null, host);
        host.remove();
    });
};

/**
 * The row's channel and number, as HTML laid over the chart at the tick's
 * position. Not a `<foreignObject>` inside the SVG: WebKit (every browser on
 * iOS) ignores the tick's transform there and draws every row at the chart's
 * corner.
 */
const placeAxisChannel = (
    layer: HTMLDivElement,
    svgOffset: { x: number; y: number },
    tick: SVGGElement,
    row: (typeof props.rows)[number],
): void => {
    const origin = tick.getCTM();

    if (!origin) {
        return;
    }

    const host = document.createElement('div');
    const width = columnWidth.value;

    host.className = 'absolute';
    host.dataset.testid = 'analytics-axis-channel';
    host.dataset.platform = row.account.platform;
    host.style.left = `${svgOffset.x}px`;
    host.style.top = `${svgOffset.y + origin.f - AVATAR_SIZE / 2}px`;
    host.style.width = `${width}px`;
    host.style.height = `${AVATAR_SIZE}px`;

    const content = h(AxisChannelRow, {
        account: row.account,
        value: rowValue(row.value),
    });
    content.appContext = appContext;
    render(content, host);
    avatarHosts.push(host);
    layer.appendChild(host);
};

const decorateTicks = (svg: SVGSVGElement): void => {
    unmountAvatars();

    const layer = avatarLayer.value;

    if (!layer) {
        return;
    }

    const svgBox = svg.getBoundingClientRect();
    const layerBox = layer.getBoundingClientRect();
    const svgOffset = {
        x: svgBox.left - layerBox.left,
        y: svgBox.top - layerBox.top,
    };

    svg.querySelectorAll<SVGGElement>('g[data-account-tick]').forEach(
        (tick) => {
            const row = rowAt(Number(tick.getAttribute('data-account-tick')));

            if (row) {
                placeAxisChannel(layer, svgOffset, tick, row);
            }
        },
    );
};
onBeforeUnmount(unmountAvatars);

const barAttributes = {
    [VisStackedBarSelectors.bar]: { 'data-testid': 'analytics-account-bar' },
};
</script>

<template>
    <ChartContainer
        :config="chartConfig"
        class="relative w-full"
        :style="{ height: chartHeight }"
        data-testid="accounts-unovis-bar-chart"
    >
        <div
            ref="avatarLayer"
            class="pointer-events-none absolute inset-0 z-10"
            aria-hidden="true"
        />
        <VisXYContainer
            :data="chartData"
            y-direction="south"
            :padding="chartPadding"
            :on-render-complete="decorateTicks"
        >
            <VisStackedBar
                :x="indexAccessor"
                :y="valueAccessor"
                :color="rowColor"
                orientation="horizontal"
                :rounded-corners="4"
                :bar-padding="0.4"
                :bar-max-width="20"
                :attributes="barAttributes"
            />
            <VisAxis
                type="x"
                :tick-format="formatCountTick"
                :num-ticks="5"
                :grid-line="true"
                :domain-line="false"
                :tick-line="false"
            />
            <VisAxis
                type="y"
                :tick-format="blankTick"
                :tick-values="categoryTicks"
                :duration="0"
                :attributes="tickAttributes"
                :grid-line="false"
                :domain-line="false"
                :tick-line="false"
            />
            <ChartTooltip :triggers="tooltipTriggers" />
        </VisXYContainer>
    </ChartContainer>
</template>
