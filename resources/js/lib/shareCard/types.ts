import type { ChartMode, MetricDelta } from '@/lib/channelMetrics';
import type { SeriesMetric } from '@/types/analytics';
import type { VerifiedBadge } from '@/types/social-account';

import type { ShareTint } from './theme';

/** One line per channel, the channel chart, columns split by channel over time, one bar per channel, or the Summary's numbers. */
export type ShareShape = 'lines' | 'combo' | 'stacked' | 'rows' | 'tiles';

/** The channel a `rows` bar belongs to, drawn next to its name like the page's chart. */
export type ShareChannel = {
    platform: string;
    /** The stored avatar the report sends; the card draws the network's logo when it cannot load it with CORS. */
    avatar: string | null;
    verified: VerifiedBadge | null;
};

/** One channel's part of a `stacked` column, in the channel's color. */
export type ShareSegment = { value: number; color: string };

/** A bucket over time, or a channel for `rows`, which may bring its own color. */
export type SharePoint = {
    label: string;
    value: number | null;
    color?: string;
    channel?: ShareChannel;
    /** A channel's number as its `rows` line prints it, before its bar. */
    figure?: string;
    /** The column's parts, bottom up, for `stacked`; `value` is their total. */
    segments?: ShareSegment[];
};

/** One channel's line over the points' dates for `lines`, ending in its avatar and latest number. */
export type ShareLine = {
    color: string;
    channel: ShareChannel;
    /** The channel's latest number as the line's label prints it. */
    figure: string;
    /** One value per point, null before the channel's first reading. */
    values: (number | null)[];
};

/**
 * One metric of the channel chart for `combo`, drawn the way the page draws
 * it: bars or a line on its own axis, in its color, for each period shown.
 */
export type ShareSeries = {
    metric: SeriesMetric;
    label: string;
    color: string;
    kind: 'bar' | 'line';
    axis: 'left' | 'right';
    /** One value per point, in this period and in the previous one. */
    current: (number | null)[];
    previous: (number | null)[];
    /** The period's totals as the page's legend prints them, and how the metric moved. */
    figure: string;
    previousFigure: string;
    change: MetricDelta | null;
};

/** The images a card draws, loaded before painting: a canvas only draws images already loaded. */
export type ShareImages = ReadonlyMap<string, HTMLImageElement>;

/** One number of the Summary, as its card prints it; `missing` while the metric has no value, which the card draws muted and the post leaves out. */
export type ShareTile = { label: string; figure: string; missing: boolean; change: MetricDelta | null };

/** What a share option draws, which names its file: one metric of a series, the channel's chart, or the whole Summary. */
type ShareMetricKey = SeriesMetric | 'channel' | 'summary';

/** One metric a share dialog can draw, with the post text it opens the composer with. */
export type ShareMetricOption = {
    key: ShareMetricKey;
    label: string;
    figure: string;
    change: MetricDelta | null;
    points: SharePoint[];
    shape: ShareShape;
    /** Every number of the Summary, for the summary card. */
    tiles: ShareTile[];
    /** One line per channel, for `lines`. */
    lines: ShareLine[];
    /** The channel chart's metrics, for `combo`. */
    series: ShareSeries[];
    /** The periods `combo` shows, as the page's chart toggle sets them. */
    chartMode: ChartMode;
    caption: string;
};

/** The metric to draw, and how: in which tint, for whom, over which period, signed how. */
export type ShareCardOptions = Omit<ShareMetricOption, 'caption'> & {
    tint: ShareTint;
    /** The workspace or channel the numbers belong to. */
    subject: string;
    /** The period, as the page prints it. */
    range: string;
    /** The signature: "Made with TryPost", translated. */
    mark: string;
};

export type Point = { x: number; y: number };

export type Box = Point & { width: number; height: number };
