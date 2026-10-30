import { trans } from 'laravel-vue-i18n';

import date from '@/date';
import { formatSeriesValue } from '@/lib/channelMetrics';
import type {
    ShareChange,
    ShareMetricOption,
} from '@/lib/insightsShareCard';
import { formatNumberCompact, formatPercentChange } from '@/lib/utils';
import type {
    ChannelMetricSeries,
    SeriesMetric,
    WorkspaceAnalyticsReport,
} from '@/types/analytics';

/** Where the numbers come from: the whole workspace, or one channel by name. */
export type ShareScope = { channel: string | null };

/** A change the way the page labels it: a count for followers, else a percentage. */
export const shareChange = (
    change: number | null | undefined,
    byCount = false,
): ShareChange | null => {
    if (change === null || change === undefined) {
        return null;
    }

    return {
        label: byCount
            ? `${change > 0 ? '+' : ''}${formatNumberCompact(change)}`
            : formatPercentChange(change),
        direction: change > 0 ? 'up' : change < 0 ? 'down' : 'flat',
    };
};

export const shareCaption = (
    scope: ShareScope,
    metric: string,
    value: string,
    range: string,
): string =>
    scope.channel === null
        ? trans('analytics.share.caption_workspace', { metric, value, range })
        : trans('analytics.share.caption_channel', {
              metric,
              value,
              range,
              channel: scope.channel,
          });

/**
 * The workspace's followers over the period: every channel added up, each
 * carrying its last known count over the days it has no reading, like the
 * page's line chart.
 */
export const followersShareOption = (
    followers: WorkspaceAnalyticsReport['followers'],
    range: string,
): ShareMetricOption => {
    const keys = followers.accounts.map((account) => account.social_account_key);
    const lastKnown: Record<string, number> = {};
    const points = followers.series.map((point) => {
        keys.forEach((key) => {
            const value = point.accounts[key];

            if (typeof value === 'number') {
                lastKnown[key] = value;
            }
        });

        const known = Object.values(lastKnown);

        return {
            label: date.formatMonthDay(point.date),
            value: known.length ? known.reduce((sum, value) => sum + value, 0) : null,
        };
    });
    const net = followers.accounts.reduce(
        (sum, account) => sum + (account.net ?? 0),
        0,
    );
    const label = trans('analytics.dashboard.followers');
    const figure =
        followers.total === null ? '—' : formatNumberCompact(followers.total);

    return {
        key: 'followers',
        label,
        figure,
        change: followers.accounts.some((account) => account.net !== null)
            ? shareChange(net, true)
            : null,
        points,
        shape: 'line',
        tick: (value) => formatNumberCompact(value),
        caption: shareCaption({ channel: null }, label, figure, range),
    };
};

/** One option per metric of a channel's series: followers as a line, the rest as bars. */
export const channelSeriesShareOptions = (
    series: ChannelMetricSeries,
    channel: string,
    range: string,
): ShareMetricOption[] =>
    series.metrics
        .filter((metric) => metric !== 'growth_rate')
        .map((metric: SeriesMetric) => {
        const label = trans(`analytics.channel.series.${metric}`);
        const figure = formatSeriesValue(series.totals.current[metric], metric);
        const current = series.totals.current[metric];
        const previous = series.totals.previous[metric];
        const isFollowers = metric === 'followers';

        return {
            key: metric,
            label,
            figure,
            change:
                current === null || current === undefined || previous === null || previous === undefined
                    ? null
                    : isFollowers
                      ? shareChange(current - previous, true)
                      : previous === 0
                        ? null
                        : shareChange(((current - previous) / previous) * 100),
            points: series.current.map((point) => ({
                label: date.formatMonthDay(point.start),
                value: point.values[metric] ?? null,
            })),
            shape: isFollowers ? 'line' : 'bar',
            tick: (value) => formatNumberCompact(value),
            caption: shareCaption({ channel }, label, figure, range),
        };
    });

/** One headline number per Summary card, as the card prints it. */
export const summaryShareOptions = (
    cards: { key: string; label: string; value: string; change: ShareChange | null }[],
    scope: ShareScope,
    range: string,
): ShareMetricOption[] =>
    cards.map((card) => ({
        key: card.key,
        label: card.label,
        figure: card.value,
        change: card.change,
        points: [],
        shape: 'bar',
        tick: (value) => formatNumberCompact(value),
        caption: shareCaption(scope, card.label, card.value, range),
    }));
