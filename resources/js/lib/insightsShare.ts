import { trans } from 'laravel-vue-i18n';

import date from '@/date';
import { accountLabel } from '@/lib/accountLabel';
import { formatSeriesValue, monthLabel } from '@/lib/channelMetrics';
import type {
    ShareChange,
    ShareMetricOption,
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

export const shareRange = (range: { start: string; end: string }): string =>
    `${date.formatDayMonthYear(range.start)} – ${date.formatDayMonthYear(range.end)}`;

/** A change the way the page labels it: a count for followers, else a percentage. */
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

/** The post text, for the whole workspace (`channel` null) or one channel. */
const caption = (
    channel: string | null,
    summary: boolean,
    replacements: Record<string, string>,
): string =>
    trans(
        `analytics.share.caption${summary ? '_summary' : ''}_${channel === null ? 'workspace' : 'channel'}`,
        channel === null ? replacements : { ...replacements, channel },
    );

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

/** How the page's Followers card is drawn: per channel, over time, or the change per channel. */
export type FollowersChartMode = 'bar' | 'line' | 'growth';

/**
 * The workspace's followers the way the card shows them: one bar per channel
 * (Bar), every channel added up over the period, each carrying its last known
 * count over the days it has no reading (Line), or each channel's change (Growth).
 */
export const followersShareOption = (
    followers: WorkspaceAnalyticsReport['followers'],
    range: string,
    mode: FollowersChartMode,
    colors: Record<string, string>,
): ShareMetricOption => {
    const label = trans('analytics.dashboard.followers');
    const figure =
        followers.total === null ? '—' : formatNumberCompact(followers.total);
    const lastKnown = new Map<string, number>();
    const overTime = followers.series.map((point) => {
        followers.accounts.forEach(({ social_account_key: key }) => {
            const value = point.accounts[key];

            if (typeof value === 'number') {
                lastKnown.set(key, value);
            }
        });

        return {
            label: date.formatMonthDay(point.date),
            value: lastKnown.size
                ? [...lastKnown.values()].reduce((sum, value) => sum + value, 0)
                : null,
        };
    });
    const perChannel = followers.accounts.map((account) => ({
        label: accountLabel(account),
        value: mode === 'bar' ? account.value : account.growth,
        color: colors[account.social_account_key],
    }));

    return shareOption({
        key: 'followers',
        label,
        figure,
        change: followers.accounts.some((account) => account.net !== null)
            ? shareChange(
                  followers.accounts.reduce((sum, account) => sum + (account.net ?? 0), 0),
                  true,
              )
            : null,
        points: mode === 'line' ? overTime : perChannel,
        shape: mode === 'line' ? 'line' : 'rows',
        caption: caption(null, false, { metric: label, value: figure, range }),
    });
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
            const current = series.totals.current[metric];
            const previous = series.totals.previous[metric];
            const figure = formatSeriesValue(current, metric);
            const isFollowers = metric === 'followers';
            const isCount = isFollowers || metric === 'net_followers';
            const compared = current != null && previous != null;

            return shareOption({
                key: metric,
                label,
                figure,
                change: !compared
                    ? null
                    : isCount
                      ? shareChange(current - previous, true)
                      : previous === 0
                        ? null
                        : shareChange(
                              ((current - previous) / Math.abs(previous)) * 100,
                          ),
                comparison: compared
                    ? trans('analytics.share.previous', {
                          value: formatSeriesValue(previous, metric),
                      })
                    : null,
                points: series.current.map((point) => ({
                    label:
                        series.resolution === 'monthly'
                            ? monthLabel(point.start)
                            : date.formatMonthDay(point.start),
                    value: point.values[metric] ?? null,
                })),
                shape: isFollowers ? 'line' : 'bar',
                caption: caption(channel, false, { metric: label, value: figure, range }),
            });
        });

/** Every number of the Summary in one card, as the cards print them. */
export const summaryShareOption = (
    tiles: ShareTile[],
    channel: string | null,
    range: string,
): ShareMetricOption =>
    shareOption({
        key: 'summary',
        label: trans('analytics.dashboard.summary'),
        tiles,
        caption: caption(channel, true, {
            range,
            metrics: tiles.map((tile) => `${tile.label} ${tile.figure}`).join(' · '),
        }),
    });
