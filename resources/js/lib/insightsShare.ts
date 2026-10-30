import { trans } from 'laravel-vue-i18n';

import date from '@/date';
import { formatSeriesValue } from '@/lib/channelMetrics';
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

/** Where the numbers come from: the whole workspace, or one channel by name. */
export type ShareScope = { channel: string | null };

export const shareRange = (range: { start: string; end: string }): string =>
    `${date.formatDayMonthYear(range.start)} – ${date.formatDayMonthYear(range.end)}`;

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

const caption = (
    scope: ShareScope,
    keys: { workspace: string; channel: string },
    replacements: Record<string, string>,
): string =>
    scope.channel === null
        ? trans(keys.workspace, replacements)
        : trans(keys.channel, { ...replacements, channel: scope.channel });

const metricCaption = (
    scope: ShareScope,
    metric: string,
    value: string,
    range: string,
): string =>
    caption(
        scope,
        {
            workspace: 'analytics.share.caption_workspace',
            channel: 'analytics.share.caption_channel',
        },
        { metric, value, range },
    );

/** How the page's Followers card is drawn: per channel, over time, or the change per channel. */
export type FollowersShareMode = 'bar' | 'line' | 'growth';

const accountLabel = (account: WorkspaceAnalyticsReport['followers']['accounts'][number]): string =>
    account.username ? `@${account.username}` : (account.name ?? account.platform);

/**
 * The workspace's followers the way the card shows them: one bar per channel
 * (Bar), every channel added up over the period, each carrying its last known
 * count over the days it has no reading (Line), or each channel's change (Growth).
 */
export const followersShareOption = (
    followers: WorkspaceAnalyticsReport['followers'],
    range: string,
    mode: FollowersShareMode,
    colors: Record<string, string>,
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
    const rows =
        mode === 'line'
            ? null
            : followers.accounts.map((account) => ({
                  label: accountLabel(account),
                  value: mode === 'bar' ? account.value : (account.net ?? null),
                  color: colors[account.social_account_key],
              }));

    return {
        key: 'followers',
        label,
        figure,
        change: followers.accounts.some((account) => account.net !== null)
            ? shareChange(net, true)
            : null,
        comparison: null,
        points: rows ?? points,
        shape: rows ? 'rows' : 'line',
        tick: (value) => formatNumberCompact(value),
        tiles: [],
        caption: metricCaption({ channel: null }, label, figure, range),
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
            const known =
                current !== null &&
                current !== undefined &&
                previous !== null &&
                previous !== undefined;

            return {
                key: metric,
                label,
                figure,
                change: !known
                    ? null
                    : isFollowers
                      ? shareChange(current - previous, true)
                      : previous === 0
                        ? null
                        : shareChange(((current - previous) / previous) * 100),
                comparison: known
                    ? trans('analytics.share.previous', {
                          value: formatSeriesValue(previous, metric),
                      })
                    : null,
                points: series.current.map((point) => ({
                    label: date.formatMonthDay(point.start),
                    value: point.values[metric] ?? null,
                })),
                shape: isFollowers ? 'line' : 'bar',
                tick: (value) => formatNumberCompact(value),
                tiles: [],
                caption: metricCaption({ channel }, label, figure, range),
            };
        });

/** Every number of the Summary in one card, as the cards print them. */
export const summaryShareOption = (
    tiles: ShareTile[],
    scope: ShareScope,
    range: string,
): ShareMetricOption => ({
    key: 'summary',
    label: trans('analytics.dashboard.summary'),
    figure: '',
    change: null,
    comparison: null,
    points: [],
    shape: 'bar',
    tick: (value) => formatNumberCompact(value),
    tiles,
    caption: caption(
        scope,
        {
            workspace: 'analytics.share.caption_summary_workspace',
            channel: 'analytics.share.caption_summary_channel',
        },
        {
            range,
            metrics: tiles
                .map((tile) => `${tile.label} ${tile.figure}`)
                .join(' · '),
        },
    ),
});
