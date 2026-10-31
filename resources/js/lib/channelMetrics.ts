import date from '@/date';
import { accountColor } from '@/lib/analyticsColors';
import {
    formatCountChange,
    formatNumberCompact,
    formatPercent,
    formatPercentChange,
} from '@/lib/utils';
import type {
    BucketResolution,
    ChannelMetricSeries,
    FollowerGrowthRate,
    MetricSeriesPoint,
    SeriesMetric,
} from '@/types/analytics';

export type ChartMode = 'current' | 'previous' | 'both';

export type PresetKey =
    | 'content_impact'
    | 'audience_growth'
    | 'visibility'
    | 'follower_growth_rate';

export interface SeriesSpec {
    metric: SeriesMetric;
    type: 'bar' | 'line';
    axis: 'left' | 'right';
    color: string;
}

export interface Preset {
    key: PresetKey;
    series: SeriesSpec[];
}

export type Selection =
    | { kind: 'metric'; metric: SeriesMetric }
    | { kind: 'preset'; preset: PresetKey };

export type InsightSubject =
    | 'posts'
    | 'audience'
    | 'audience_compared'
    | 'content_impact'
    | 'visibility'
    | 'reach'
    | 'views'
    | 'impressions'
    | 'growth_rate';

export type InsightDirection = 'up' | 'down' | 'flat';

export interface MetricInsight {
    subject: InsightSubject;
    direction: InsightDirection;
}

const primary = accountColor(0);
const secondary = accountColor(1);

const LEVEL_METRICS: SeriesMetric[] = ['followers', 'net_followers'];

const AUDIENCE_METRICS: SeriesMetric[] = ['reach', 'impressions', 'views'];

const STEADY_PERCENT = 5;

const STEADY_GROWTH_POINTS = 0.05;

export const POST_TOTAL_METRICS: SeriesMetric[] = [
    'reach',
    'views',
    'impressions',
    'profile_visits',
];

export const PERCENT_METRICS: SeriesMetric[] = ['growth_rate'];

export const PRESETS: Preset[] = [
    {
        key: 'content_impact',
        series: [
            { metric: 'posts', type: 'bar', axis: 'left', color: primary },
            {
                metric: 'followers',
                type: 'line',
                axis: 'right',
                color: secondary,
            },
        ],
    },
    {
        key: 'audience_growth',
        series: [
            { metric: 'followers', type: 'line', axis: 'left', color: primary },
            {
                metric: 'net_followers',
                type: 'line',
                axis: 'right',
                color: secondary,
            },
        ],
    },
    {
        key: 'visibility',
        series: [
            {
                metric: 'profile_visits',
                type: 'line',
                axis: 'left',
                color: primary,
            },
            {
                metric: 'followers',
                type: 'line',
                axis: 'right',
                color: secondary,
            },
        ],
    },
    {
        key: 'follower_growth_rate',
        series: [
            {
                metric: 'growth_rate',
                type: 'line',
                axis: 'left',
                color: secondary,
            },
        ],
    },
];

export const isLevelMetric = (metric: SeriesMetric): boolean =>
    LEVEL_METRICS.includes(metric);

export const isPercentMetric = (metric: SeriesMetric): boolean =>
    PERCENT_METRICS.includes(metric);

/** Metrics the page counts rather than measures: their change is a difference, never a percentage. */
const COUNTED_METRICS: readonly SeriesMetric[] = ['net_followers'];

/** How a metric moved since the previous period: the signed value and how the page labels it. */
export type SeriesDelta = { value: number; label: string };

/** A count's change as the page labels it: "+7", "-3". */
const countDelta = (change: number): SeriesDelta => ({ value: change, label: formatCountChange(change) });

/**
 * The followers gained or lost within the period, from its first reading to
 * its last, like the workspace's Followers card; null without two readings.
 */
const followersDelta = (series: ChannelMetricSeries): SeriesDelta | null => {
    const readings = definedValues(series.current, 'followers');

    if (readings.length < 2) {
        return null;
    }

    return countDelta(readings[readings.length - 1] - readings[0]);
};

/**
 * How a channel metric moved: followers by what the period gained or lost,
 * net new followers by the difference with the previous period ("+7"), the
 * rest by a percentage of the previous period ("+8.5%"), kept positive so a
 * loss shrinking reads as a rise. Null for a rate, which has no previous
 * period, or when there is nothing to compare.
 */
export const seriesDelta = (series: ChannelMetricSeries, metric: SeriesMetric): SeriesDelta | null => {
    if (metric === 'followers') {
        return followersDelta(series);
    }

    const current = series.totals.current[metric];
    const previous = series.totals.previous[metric];

    if (isPercentMetric(metric) || current == null || previous == null) {
        return null;
    }

    if (COUNTED_METRICS.includes(metric)) {
        return countDelta(current - previous);
    }

    if (previous === 0) {
        return null;
    }

    const change = ((current - previous) / Math.abs(previous)) * 100;

    return { value: change, label: formatPercentChange(change) };
};

/**
 * The metrics the menu offers, in menu order: followers, the network's audience metric (reach where it is
 * collected, otherwise impressions or views), profile visits, posts and net new followers.
 */
export const menuMetrics = (metrics: SeriesMetric[]): SeriesMetric[] => {
    const audience = AUDIENCE_METRICS.find((metric) =>
        metrics.includes(metric),
    );
    const order: (SeriesMetric | undefined)[] = [
        'followers',
        audience,
        'profile_visits',
        'posts',
        'net_followers',
    ];

    return order.filter(
        (metric): metric is SeriesMetric =>
            metric !== undefined && metrics.includes(metric),
    );
};

export const availablePresets = (series: ChannelMetricSeries): Preset[] =>
    PRESETS.filter((preset) =>
        preset.key === 'follower_growth_rate'
            ? Boolean(series.growth)
            : preset.series.every((spec) =>
                  series.metrics.includes(spec.metric),
              ),
    );

export const seriesFor = (selection: Selection): SeriesSpec[] => {
    if (selection.kind === 'preset') {
        return (
            PRESETS.find((preset) => preset.key === selection.preset)?.series ??
            []
        );
    }

    return [
        {
            metric: selection.metric,
            type: selection.metric === 'posts' ? 'bar' : 'line',
            axis: 'left',
            color: primary,
        },
    ];
};

export const isGrowthRate = (selection: Selection): boolean =>
    selection.kind === 'preset' &&
    selection.preset === 'follower_growth_rate';

/**
 * The monthly growth rate in the shape the chart draws: one point per month, no previous period.
 */
export const growthRateSeries = (
    growth: FollowerGrowthRate,
): ChannelMetricSeries => ({
    resolution: 'monthly',
    metrics: ['growth_rate'],
    range: growth.range,
    previous_range: growth.range,
    current: growth.months.map((month) => ({
        start: month.start,
        end: month.end,
        values: { growth_rate: month.rate },
    })),
    previous: [],
    totals: {
        current: { growth_rate: growth.latest },
        previous: { growth_rate: growth.previous },
    },
});

const niceStep = (rough: number): number => {
    if (rough <= 0) {
        return 1;
    }

    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step = [1, 2, 2.5, 5, 10].find(
        (candidate) => candidate * magnitude >= rough,
    );

    return (step ?? 10) * magnitude;
};

export const AXIS_STEPS = 4;

/**
 * A domain split into four round steps, so every axis shares the same gridlines.
 */
export const niceDomain = (
    values: number[],
    fromZero: boolean,
    fractional = false,
): { min: number; max: number } => {
    if (values.length === 0) {
        return { min: 0, max: AXIS_STEPS };
    }

    const low = fromZero ? Math.min(0, ...values) : Math.min(...values);
    const high = Math.max(...values, fromZero ? 0 : -Infinity);
    const floor = fractional ? 0.01 : 1;
    let step = Math.max(
        floor,
        niceStep((high - low) / AXIS_STEPS || Math.abs(high) * 0.01),
    );
    let min = Math.floor(low / step) * step;

    while (min + step * AXIS_STEPS < high) {
        step = Math.max(floor, niceStep(step * 1.01));
        min = Math.floor(low / step) * step;
    }

    return { min, max: min + step * AXIS_STEPS };
};

export const definedValues = (
    points: MetricSeriesPoint[],
    metric: SeriesMetric,
): number[] =>
    points
        .map((point) => point.values[metric])
        .filter((value): value is number => typeof value === 'number');

export const hasEnoughHistory = (
    series: ChannelMetricSeries,
    specs: SeriesSpec[],
    mode: ChartMode,
): boolean => {
    const periods =
        mode === 'both'
            ? [series.current, series.previous]
            : [mode === 'previous' ? series.previous : series.current];

    return specs.some((spec) =>
        periods.some(
            (points) =>
                definedValues(points, spec.metric).length >=
                (isLevelMetric(spec.metric) ? 2 : 1),
        ),
    );
};

export const formatSeriesValue = (
    value: number | null | undefined,
    metric?: SeriesMetric,
): string => {
    if (metric && isPercentMetric(metric)) {
        return formatPercent(value);
    }

    return formatNumberCompact(value);
};

export const bucketLabel = (
    point: MetricSeriesPoint | undefined,
    resolution: ChannelMetricSeries['resolution'],
): string => {
    if (!point) {
        return '';
    }

    if (resolution === 'monthly') {
        return monthLabel(point.start);
    }

    return resolution === 'weekly' && point.start !== point.end
        ? `${date.formatMonthDay(point.start)} – ${date.formatMonthDay(point.end)}`
        : date.formatDayMonthYear(point.start);
};

export const monthLabel = (day: string): string => {
    const [year, month] = day.split('-').map(Number);

    return date.formatMonthYear(month, year);
};

/** A bucket on the chart's time axis: the month for monthly buckets, else the day it starts. */
export const axisBucketLabel = (
    bucket: { start: string },
    resolution: BucketResolution,
): string => {
    if (resolution === 'monthly') {
        return monthLabel(bucket.start);
    }

    return date.formatMonthDay(bucket.start);
};

const direction = (
    current: number,
    previous: number,
): InsightDirection => {
    if (previous === 0) {
        if (current === 0) {
            return 'flat';
        }

        return current > 0 ? 'up' : 'down';
    }

    const change = ((current - previous) / Math.abs(previous)) * 100;

    if (change >= STEADY_PERCENT) {
        return 'up';
    }

    return change <= -STEADY_PERCENT ? 'down' : 'flat';
};

const isNumber = (value: number | null | undefined): value is number =>
    typeof value === 'number';

const compareTotals = (
    series: ChannelMetricSeries,
    metric: SeriesMetric,
    subject: InsightSubject,
): MetricInsight | null => {
    const current = series.totals.current[metric];
    const previous = series.totals.previous[metric];

    return isNumber(current) && isNumber(previous)
        ? { subject, direction: direction(current, previous) }
        : null;
};

const audience = (
    series: ChannelMetricSeries,
    mode: ChartMode,
): MetricInsight | null => {
    const current = series.totals.current.net_followers;
    const previous = series.totals.previous.net_followers;

    if (mode === 'both') {
        return isNumber(current) && isNumber(previous)
            ? {
                  subject: 'audience_compared',
                  direction: direction(current, previous),
              }
            : null;
    }

    if (!isNumber(current)) {
        return null;
    }

    return {
        subject: 'audience',
        direction: current > 0 ? 'up' : current < 0 ? 'down' : 'flat',
    };
};

const contentImpact = (series: ChannelMetricSeries): MetricInsight | null => {
    const { current, previous } = series.totals;

    if (
        !isNumber(current.net_followers) ||
        !isNumber(previous.net_followers) ||
        !current.posts ||
        !previous.posts
    ) {
        return null;
    }

    return {
        subject: 'content_impact',
        direction: direction(
            current.net_followers / current.posts,
            previous.net_followers / previous.posts,
        ),
    };
};

const growthRate = (growth: FollowerGrowthRate): MetricInsight | null => {
    if (!isNumber(growth.latest) || !isNumber(growth.previous)) {
        return null;
    }

    const change = growth.latest - growth.previous;

    return {
        subject: 'growth_rate',
        direction:
            Math.abs(change) < STEADY_GROWTH_POINTS
                ? 'flat'
                : change > 0
                  ? 'up'
                  : 'down',
    };
};

/**
 * A one-line, rule-based reading of the selected chart against the previous period.
 */
export const metricInsight = (
    series: ChannelMetricSeries,
    selection: Selection,
    mode: ChartMode,
): MetricInsight | null => {
    if (selection.kind === 'preset') {
        switch (selection.preset) {
            case 'content_impact':
                return contentImpact(series);
            case 'audience_growth':
                return audience(series, mode);
            case 'visibility':
                return compareTotals(series, 'profile_visits', 'visibility');
            case 'follower_growth_rate':
                return series.growth ? growthRate(series.growth) : null;
        }
    }

    switch (selection.metric) {
        case 'followers':
        case 'net_followers':
            return audience(series, mode);
        case 'profile_visits':
            return compareTotals(series, 'profile_visits', 'visibility');
        case 'reach':
        case 'views':
        case 'impressions':
            return compareTotals(series, selection.metric, selection.metric);
        default:
            return compareTotals(series, 'posts', 'posts');
    }
};
