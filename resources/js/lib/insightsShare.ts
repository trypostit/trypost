import { trans } from 'laravel-vue-i18n';

import date from '@/date';
import { accountLabel } from '@/lib/accountLabel';
import { axisBucketLabel, formatSeriesValue } from '@/lib/channelMetrics';
import type {
    ShareChange,
    ShareMetricOption,
    SharePoint,
    ShareTile,
} from '@/lib/insightsShareCard';
import { formatNumberCompact, formatPercentChange } from '@/lib/utils';
import type {
    ChannelMetricSeries,
    SeriesMetric,
    WorkspaceAnalyticsReport,
} from '@/types/analytics';

/**
 * Builders for the share dialog. They run when the dialog opens, never in a
 * script-level computed, so every `trans()` here reads the loaded locale.
 */

type Followers = WorkspaceAnalyticsReport['followers'];

/** How the page's Followers card is drawn: per channel, over time, or the change per channel. */
export type FollowersChartMode = 'bar' | 'line' | 'growth';

/** Metrics the page counts rather than measures: their change is a difference, never a percentage. */
const COUNTED_METRICS: readonly SeriesMetric[] = ['followers', 'net_followers'];

const sum = (values: number[]): number =>
    values.reduce((total, value) => total + value, 0);

export const shareRange = (range: { start: string; end: string }): string =>
    `${date.formatDayMonthYear(range.start)} – ${date.formatDayMonthYear(range.end)}`;

/** A change the way the page labels it: a signed count ("+12"), or a percentage. */
export const shareChange = (
    change: number | null | undefined,
    byCount = false,
): ShareChange | null => {
    if (change == null) {
        return null;
    }

    return {
        label: byCount
            ? `${change > 0 ? '+' : ''}${formatNumberCompact(change)}`
            : formatPercentChange(change),
        direction: change > 0 ? 'up' : change < 0 ? 'down' : 'flat',
    };
};

/** The post text for one metric, of the whole workspace (`channel` null) or of one channel. */
const metricCaption = (
    channel: string | null,
    metric: string,
    value: string,
    range: string,
): string =>
    channel === null
        ? trans('analytics.share.caption_workspace', { metric, value, range })
        : trans('analytics.share.caption_channel', { metric, value, range, channel });

/** The post text for the Summary: every number in one line. */
const summaryCaption = (
    channel: string | null,
    tiles: ShareTile[],
    range: string,
): string => {
    const metrics = tiles.map((tile) => `${tile.label} ${tile.figure}`).join(' · ');

    return channel === null
        ? trans('analytics.share.caption_summary_workspace', { metrics, range })
        : trans('analytics.share.caption_summary_channel', { metrics, range, channel });
};

/** An option with nothing drawn but what it is given. */
const shareOption = (
    option: Pick<ShareMetricOption, 'key' | 'label' | 'caption'> &
        Partial<ShareMetricOption>,
): ShareMetricOption => ({
    figure: '',
    change: null,
    comparison: null,
    points: [],
    shape: 'bar',
    tiles: [],
    ...option,
});

/** Every channel added up day by day, each carrying its last known count over the days it has no reading. */
const followersOverTime = (followers: Followers): SharePoint[] => {
    const lastKnown = new Map<string, number>();

    return followers.series.map((point) => {
        followers.accounts.forEach(({ social_account_key: key }) => {
            const value = point.accounts[key];

            if (typeof value === 'number') {
                lastKnown.set(key, value);
            }
        });

        return {
            label: date.formatMonthDay(point.date),
            value: lastKnown.size ? sum([...lastKnown.values()]) : null,
        };
    });
};

/** One row per channel in its color: its followers (Bar) or its change over the period (Growth). */
const followersPerChannel = (
    followers: Followers,
    mode: Exclude<FollowersChartMode, 'line'>,
    colors: Record<string, string>,
): SharePoint[] =>
    followers.accounts.map((account) => ({
        label: accountLabel(account),
        value: mode === 'bar' ? account.value : account.growth,
        color: colors[account.social_account_key],
    }));

/** The followers gained or lost over the period, across the channels that report it. */
const followersChange = (followers: Followers): ShareChange | null => {
    const nets = followers.accounts
        .map((account) => account.net)
        .filter((net): net is number => net != null);

    return nets.length ? shareChange(sum(nets), true) : null;
};

/** The workspace's followers the way the page's Followers card draws them in `mode`. */
export const followersShareOption = (
    followers: Followers,
    range: string,
    mode: FollowersChartMode,
    colors: Record<string, string>,
): ShareMetricOption => {
    const label = trans('analytics.dashboard.followers');
    const figure = formatSeriesValue(followers.total, 'followers');

    return shareOption({
        key: 'followers',
        label,
        figure,
        change: followersChange(followers),
        points:
            mode === 'line'
                ? followersOverTime(followers)
                : followersPerChannel(followers, mode, colors),
        shape: mode === 'line' ? 'line' : 'rows',
        caption: metricCaption(null, label, figure, range),
    });
};

/** A series metric the card can draw: growth rate has no figure of its own to show. */
export const isShareableSeriesMetric = (metric: SeriesMetric): boolean =>
    metric !== 'growth_rate';

/**
 * How a channel metric moved since the previous period: a difference for the
 * counted metrics, else a percentage of the previous value, kept positive so a
 * loss shrinking reads as a rise. Null when a total is unknown or there is no
 * previous value to compare with.
 */
const seriesChange = (
    metric: SeriesMetric,
    current: number | null | undefined,
    previous: number | null | undefined,
): ShareChange | null => {
    if (current == null || previous == null) {
        return null;
    }

    if (COUNTED_METRICS.includes(metric)) {
        return shareChange(current - previous, true);
    }

    if (previous === 0) {
        return null;
    }

    return shareChange(((current - previous) / Math.abs(previous)) * 100);
};

/** What the change is measured against: "vs 120 in the previous period". */
const seriesComparison = (
    metric: SeriesMetric,
    current: number | null | undefined,
    previous: number | null | undefined,
): string | null =>
    current == null || previous == null
        ? null
        : trans('analytics.share.previous', {
              value: formatSeriesValue(previous, metric),
          });

/** One metric of a channel's series: followers as a line, the rest as bars. */
const seriesShareOption = (
    series: ChannelMetricSeries,
    metric: SeriesMetric,
    channel: string,
    range: string,
): ShareMetricOption => {
    const label = trans(`analytics.channel.series.${metric}`);
    const current = series.totals.current[metric];
    const previous = series.totals.previous[metric];
    const figure = formatSeriesValue(current, metric);

    return shareOption({
        key: metric,
        label,
        figure,
        change: seriesChange(metric, current, previous),
        comparison: seriesComparison(metric, current, previous),
        points: series.current.map((point) => ({
            label: axisBucketLabel(point, series.resolution),
            value: point.values[metric] ?? null,
        })),
        shape: metric === 'followers' ? 'line' : 'bar',
        caption: metricCaption(channel, label, figure, range),
    });
};

/** One option per metric of a channel's series the card can draw. */
export const channelSeriesShareOptions = (
    series: ChannelMetricSeries,
    channel: string,
    range: string,
): ShareMetricOption[] =>
    series.metrics
        .filter(isShareableSeriesMetric)
        .map((metric) => seriesShareOption(series, metric, channel, range));

/** Every number of the Summary in one card, as the cards print them. */
export const summaryShareOption = (
    tiles: ShareTile[],
    channel: string | null,
    range: string,
): ShareMetricOption =>
    shareOption({
        key: 'summary',
        label: trans('analytics.dashboard.summary'),
        shape: 'tiles',
        tiles,
        caption: summaryCaption(channel, tiles, range),
    });
