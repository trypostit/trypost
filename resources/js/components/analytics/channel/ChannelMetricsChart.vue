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
    axisDomain,
    axisTickLabels,
    bucketLabel,
    chartPeriods,
    definedValues,
    shownPeriod,
    type ChartMode,
    type SeriesSpec,
} from '@/lib/channelMetrics';
import type {
    ChannelMetricSeries,
    MetricSeriesPoint,
    PublicationPeriod,
    SeriesMetric,
} from '@/types/analytics';

import ChannelMetricsTooltip from './ChannelMetricsTooltip.vue';

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
    labels: Record<SeriesMetric, string>;
}>();

const DASH = [5, 4];
const tickValues = Array.from(
    { length: AXIS_STEPS + 1 },
    (_, index) => index / AXIS_STEPS,
);

const shownPeriods = computed((): PublicationPeriod[] => chartPeriods(props.mode));

/** The metrics drawn on an axis. */
const axisMetrics = (axis: SeriesSpec['axis']): SeriesMetric[] =>
    props.specs.filter((spec) => spec.axis === axis).map((spec) => spec.metric);

const domainFor = (axis: SeriesSpec['axis']): { min: number; max: number } | null => {
    const metrics = axisMetrics(axis);
    const values = metrics.flatMap((metric) =>
        shownPeriods.value.flatMap((period) => definedValues(props.series[period], metric)),
    );

    return axisDomain(metrics, values);
};

const domains = computed(() => ({ left: domainFor('left'), right: domainFor('right') }));

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
    (period: PublicationPeriod, metric: SeriesMetric) =>
    (point: ChartPoint): number | undefined => {
        const value = point[`${period}_${metric}`];

        return typeof value === 'number' ? value : undefined;
    };
const barAccessor =
    (period: PublicationPeriod, metric: SeriesMetric) =>
    (point: ChartPoint): number =>
        accessor(period, metric)(point) ?? 0;
const xAccessor = (point: ChartPoint): number => point.index;

const hatch = (metric: SeriesMetric): string =>
    `url(#insights-hatch-${metric})`;

type Layer = {
    key: string;
    spec: SeriesSpec;
    kind: 'grouped' | 'bar' | 'line';
    period: PublicationPeriod;
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

/** Each axis' tick labels, bottom up, read by the axis' tick position. */
const axisLabels = computed((): Record<SeriesSpec['axis'], string[]> => ({
    left: tickLabelsFor('left'),
    right: tickLabelsFor('right'),
}));

const tickLabelsFor = (axis: SeriesSpec['axis']): string[] => {
    const domain = domains.value[axis];

    if (!domain) {
        return [];
    }

    return axisTickLabels(domain, axisMetrics(axis));
};

const tickLabel =
    (axis: SeriesSpec['axis']) =>
    (tick: number | Date): string => {
        if (typeof tick !== 'number') {
            return '';
        }

        return axisLabels.value[axis][Math.round(tick * AXIS_STEPS)] ?? '';
    };

const xTick = (tick: number | Date): string => {
    const index = typeof tick === 'number' ? Math.round(tick) : 0;
    const point = props.series[shownPeriod(props.mode)][index];

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
            { label: props.labels[spec.metric], color: spec.color },
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
/** Room for an axis' tick labels, and the plain edge on the right when no metric uses that axis. */
const MARGIN = { top: 12, bottom: 28, axis: 56, edge: 12 };

const rightMargin = (): number => {
    if (domains.value.right) {
        return MARGIN.axis;
    }

    return MARGIN.edge;
};

const margin = computed(() => ({
    top: MARGIN.top,
    right: rightMargin(),
    bottom: MARGIN.bottom,
    left: MARGIN.axis,
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
