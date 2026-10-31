<script setup lang="ts">
import {
    VisAxis,
    VisGroupedBar,
    VisGroupedBarSelectors,
    VisLine,
    VisStackedBar,
    VisStackedBarSelectors,
    VisXYContainer,
} from '@unovis/vue';
import { computed, getCurrentInstance } from 'vue';

import {
    ChartContainer,
    ChartCrosshair,
    ChartTooltip,
    componentToString,
    type ChartConfig,
} from '@/components/ui/chart';
import {
    AXIS_STEPS,
    axisBucketLabel,
    bucketLabel,
    definedValues,
    formatSeriesValue,
    isPercentMetric,
    niceDomain,
    type ChartMode,
    type SeriesSpec,
} from '@/lib/channelMetrics';
import { distinctCountLabels } from '@/lib/utils';
import type {
    ChannelMetricSeries,
    MetricSeriesPoint,
    SeriesMetric,
} from '@/types/analytics';

import ChannelMetricsTooltip from './ChannelMetricsTooltip.vue';

type Period = 'current' | 'previous';
type ChartPoint = {
    index: number;
    current: MetricSeriesPoint;
    previous: MetricSeriesPoint | undefined;
    [key: string]: number | MetricSeriesPoint | undefined;
};

const props = defineProps<{
    series: ChannelMetricSeries;
    specs: SeriesSpec[];
    mode: ChartMode;
    labels: Partial<Record<SeriesMetric, string>>;
}>();

const DASH = [5, 4];
const tickValues = Array.from(
    { length: AXIS_STEPS + 1 },
    (_, index) => index / AXIS_STEPS,
);

const shownPeriods = computed<Period[]>(() =>
    props.mode === 'both'
        ? ['current', 'previous']
        : [props.mode === 'previous' ? 'previous' : 'current'],
);

const domains = computed(() => {
    const domainFor = (axis: SeriesSpec['axis']) => {
        const specs = props.specs.filter((spec) => spec.axis === axis);

        if (specs.length === 0) {
            return null;
        }

        const values = specs.flatMap((spec) =>
            shownPeriods.value.flatMap((period) =>
                definedValues(props.series[period], spec.metric),
            ),
        );

        return niceDomain(
            values,
            specs.some((spec) => spec.metric !== 'followers'),
            specs.some((spec) => isPercentMetric(spec.metric)),
        );
    };

    return { left: domainFor('left'), right: domainFor('right') };
});

const normalise = (
    value: number | null | undefined,
    axis: SeriesSpec['axis'],
): number | undefined => {
    const domain = domains.value[axis];

    if (value === null || value === undefined || !domain) {
        return undefined;
    }

    return (value - domain.min) / (domain.max - domain.min);
};

const chartData = computed<ChartPoint[]>(() =>
    props.series.current.map((current, index) => {
        const previous = props.series.previous[index];
        const point: ChartPoint = { index, current, previous };

        props.specs.forEach((spec) => {
            point[`current_${spec.metric}`] = normalise(
                current.values[spec.metric],
                spec.axis,
            );
            point[`previous_${spec.metric}`] = normalise(
                previous?.values[spec.metric],
                spec.axis,
            );
        });

        return point;
    }),
);

const accessor =
    (period: Period, metric: SeriesMetric) =>
    (point: ChartPoint): number | undefined => {
        const value = point[`${period}_${metric}`];

        return typeof value === 'number' ? value : undefined;
    };
const barAccessor =
    (period: Period, metric: SeriesMetric) =>
    (point: ChartPoint): number =>
        accessor(period, metric)(point) ?? 0;
const xAccessor = (point: ChartPoint): number => point.index;

const hatch = (metric: SeriesMetric): string =>
    `url(#insights-hatch-${metric})`;

type Layer = {
    key: string;
    spec: SeriesSpec;
    kind: 'grouped' | 'bar' | 'line';
    period: Period;
    dashed: boolean;
};

const layers = computed<Layer[]>(() =>
    props.specs.flatMap((spec): Layer[] => {
        if (spec.type === 'bar' && props.mode === 'both') {
            return [
                {
                    key: `both-${spec.metric}`,
                    spec,
                    kind: 'grouped',
                    period: 'current',
                    dashed: false,
                },
            ];
        }

        return shownPeriods.value.map((period) => ({
            key: `${period}-${spec.metric}`,
            spec,
            kind: spec.type === 'bar' ? 'bar' : 'line',
            period,
            dashed: period === 'previous',
        }));
    }),
);

const groupedAccessors = (metric: SeriesMetric) => [
    barAccessor('current', metric),
    barAccessor('previous', metric),
];

const groupedColor =
    (spec: SeriesSpec) =>
    (_point: ChartPoint, index: number): string =>
        index === 0 ? spec.color : hatch(spec.metric);

const svgDefs = computed(() =>
    props.specs
        .filter((spec) => spec.type === 'bar')
        .map(
            (spec) =>
                `<pattern id="insights-hatch-${spec.metric}" patternUnits="userSpaceOnUse" width="5" height="5" patternTransform="rotate(45)"><rect width="5" height="5" style="fill: ${spec.color}" fill-opacity="0.12"/><rect width="2" height="5" style="fill: ${spec.color}"/></pattern>`,
        )
        .join(''),
);

/**
 * Each count axis's tick labels, compact but never two alike: a narrow range
 * (9,330 to 9,355 followers) gets the decimals it needs, else whole numbers.
 */
const axisCountLabels = computed(
    (): Record<SeriesSpec['axis'], string[]> => ({
        left: distinctCountLabels(axisTickValues('left')),
        right: distinctCountLabels(axisTickValues('right')),
    }),
);

/** The values an axis' ticks stand for, from its domain. */
const axisTickValues = (axis: SeriesSpec['axis']): number[] => {
    const domain = domains.value[axis];

    if (!domain) {
        return [];
    }

    return tickValues.map((tick) => domain.min + tick * (domain.max - domain.min));
};

const tickLabel =
    (axis: SeriesSpec['axis']) =>
    (tick: number | Date): string => {
        const domain = domains.value[axis];

        if (!domain || typeof tick !== 'number') {
            return '';
        }

        const spec = props.specs.find((candidate) => candidate.axis === axis);
        const value = domain.min + tick * (domain.max - domain.min);

        if (spec && isPercentMetric(spec.metric)) {
            return formatSeriesValue(value, spec.metric);
        }

        return axisCountLabels.value[axis][Math.round(tick * AXIS_STEPS)] ?? '';
    };

const xTick = (tick: number | Date): string => {
    const index = typeof tick === 'number' ? Math.round(tick) : 0;
    const point =
        props.mode === 'previous'
            ? props.series.previous[index]
            : props.series.current[index];

    if (!point) {
        return '';
    }

    return axisBucketLabel(point, props.series.resolution);
};

const dateLabel = (point: MetricSeriesPoint | undefined): string =>
    bucketLabel(point, props.series.resolution);

const config = computed<ChartConfig>(() =>
    Object.fromEntries(
        props.specs.map((spec) => [
            spec.metric,
            { label: props.labels[spec.metric] ?? '', color: spec.color },
        ]),
    ),
);

const appContext = getCurrentInstance()?.appContext ?? null;
const tooltipTemplate = computed(() =>
    componentToString(config.value, ChannelMetricsTooltip, {
        specs: props.specs,
        labels: props.labels,
        mode: props.mode,
        dateLabel,
    }, appContext),
);

const xDomain = computed<[number, number]>(() => [
    -0.5,
    Math.max(props.series.current.length - 0.5, 0.5),
]);

const barAttributes = {
    [VisStackedBarSelectors.bar]: { 'data-testid': 'insights-metrics-bar' },
};
const groupedBarAttributes = {
    [VisGroupedBarSelectors.bar]: { 'data-testid': 'insights-metrics-bar' },
};
const margin = computed(() => ({
    top: 12,
    right: domains.value.right ? 48 : 12,
    bottom: 28,
    left: 48,
}));
const numTicks = computed(() =>
    Math.min(props.series.current.length, 6),
);
</script>

<template>
    <ChartContainer
        :config="config"
        cursor
        aria-hidden="true"
        class="relative h-64 w-full sm:h-72"
        data-testid="insights-metrics-chart"
    >
        <VisXYContainer
            :data="chartData"
            :x-domain="xDomain"
            :y-domain="[0, 1]"
            :auto-margin="false"
            :margin="margin"
            :svg-defs="svgDefs"
        >
            <template v-for="layer in layers" :key="layer.key">
                <VisGroupedBar
                    v-if="layer.kind === 'grouped'"
                    :x="xAccessor"
                    :y="groupedAccessors(layer.spec.metric)"
                    :color="groupedColor(layer.spec)"
                    :data-step="1"
                    :group-padding="0.25"
                    :bar-padding="0.1"
                    :group-max-width="28"
                    :bar-min-height="0"
                    :rounded-corners="4"
                    :attributes="groupedBarAttributes"
                />
                <VisStackedBar
                    v-else-if="layer.kind === 'bar'"
                    :x="xAccessor"
                    :y="barAccessor(layer.period, layer.spec.metric)"
                    :color="
                        layer.period === 'previous'
                            ? hatch(layer.spec.metric)
                            : layer.spec.color
                    "
                    :data-step="1"
                    :bar-padding="0.35"
                    :bar-max-width="16"
                    :rounded-corners="4"
                    :attributes="barAttributes"
                />
                <VisLine
                    v-else
                    :x="xAccessor"
                    :y="accessor(layer.period, layer.spec.metric)"
                    :color="layer.spec.color"
                    :line-width="2"
                    :line-dash-array="layer.dashed ? DASH : undefined"
                    curve-type="monotoneX"
                />
            </template>
            <VisAxis
                type="x"
                :tick-format="xTick"
                :num-ticks="numTicks"
                :grid-line="false"
                :domain-line="false"
                :tick-line="false"
            />
            <VisAxis
                type="y"
                :tick-format="tickLabel('left')"
                :tick-values="tickValues"
                :grid-line="true"
                :domain-line="false"
                :tick-line="false"
            />
            <ChartCrosshair
                :template="tooltipTemplate"
                color="var(--foreground)"
                :circleRadius="5"
                strokeColor="var(--card)"
                :strokeWidth="2"
            />
            <ChartTooltip />
        </VisXYContainer>
        <div
            v-if="domains.right"
            class="pointer-events-none absolute inset-0"
            data-testid="insights-metrics-right-axis"
        >
            <VisXYContainer
                :data="chartData"
                :x-domain="xDomain"
                :y-domain="[0, 1]"
                :auto-margin="false"
                :margin="margin"
            >
                <VisAxis
                    type="y"
                    position="right"
                    :tick-format="tickLabel('right')"
                    :tick-values="tickValues"
                    :grid-line="false"
                    :domain-line="false"
                    :tick-line="false"
                />
            </VisXYContainer>
        </div>
    </ChartContainer>
</template>
