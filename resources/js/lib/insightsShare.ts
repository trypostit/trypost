import { trans } from 'laravel-vue-i18n';

import date from '@/date';
import { activeLocale } from '@/language';
import { axisBucketLabel, formatSeriesValue } from '@/lib/channelMetrics';
import type {
    ShareChange,
    ShareMetricOption,
    SharePoint,
    ShareShape,
    ShareTile,
} from '@/lib/insightsShareCard';
import { formatCountChange, formatPercentChange } from '@/lib/utils';
import { avatar } from '@/routes/app/channels';
import {
    type AccountIdentityData,
    accountLabel,
    type ChannelMetricSeries,
    type FollowerAccount,
    type SeriesMetric,
    type WorkspaceAnalyticsReport,
} from '@/types/analytics';

/**
 * Builders for the share dialog. They run when the dialog opens, never in a
 * script-level computed, so every `trans()` here reads the loaded locale.
 */

type Followers = WorkspaceAnalyticsReport['followers'];

type Posts = WorkspaceAnalyticsReport['posts'];

/** How the page's Followers card is drawn: per channel, over time, or the change per channel. */
export type FollowersChartMode = 'bar' | 'line' | 'growth';

/** The share card draws each mode of the Followers card the way the page does. */
const FOLLOWERS_SHAPES: Record<FollowersChartMode, ShareShape> = {
    bar: 'rows',
    line: 'line',
    growth: 'rows',
};

/** What a channel's row shows in the per-channel modes: its followers, or its change. */
const FOLLOWERS_ROW_VALUES: Record<
    Exclude<FollowersChartMode, 'line'>,
    (account: FollowerAccount) => number | null
> = {
    bar: (account) => account.value,
    growth: (account) => account.growth,
};

/** How the page's Posts card is drawn: per channel, or over time split by channel. */
export type PostsChartMode = 'bar' | 'stacked';

const POSTS_SHAPES: Record<PostsChartMode, ShareShape> = {
    bar: 'rows',
    stacked: 'stacked',
};

/** Metrics the page counts rather than measures: their change is a difference, never a percentage. */
const COUNTED_METRICS: readonly SeriesMetric[] = ['followers', 'net_followers'];

/** Metrics the page draws as a line; the rest are columns. */
const LINE_METRICS: readonly SeriesMetric[] = ['followers'];

const sum = (values: number[]): number =>
    values.reduce((total, value) => total + value, 0);

const changeDirection = (change: number): ShareChange['direction'] => {
    if (change > 0) {
        return 'up';
    }

    if (change < 0) {
        return 'down';
    }

    return 'flat';
};

/** A change in a count, the way the page labels it: "+12". */
export const countChange = (change: number): ShareChange => ({
    label: formatCountChange(change),
    direction: changeDirection(change),
});

/** A change as a percentage of the previous value, the way the page labels it: "+8.5%". */
export const percentChange = (change: number): ShareChange => ({
    label: formatPercentChange(change),
    direction: changeDirection(change),
});

/** The post text for one metric, of the whole workspace (`channel` null) or of one channel. */
const metricCaption = (
    channel: string | null,
    replacements: { metric: string; value: string; range: string },
): string => {
    if (channel === null) {
        return trans('analytics.share.caption_workspace', replacements);
    }

    return trans('analytics.share.caption_channel', { ...replacements, channel });
};

/** The Summary's numbers as one phrase in the reader's language: "Followers 1.2K, Posts 30 and Reach 5K". */
const summaryList = (tiles: ShareTile[]): string =>
    new Intl.ListFormat(activeLocale.value, { type: 'conjunction' }).format(
        tiles
            .filter((tile): tile is ShareTile & { figure: string } => tile.figure !== null)
            .map((tile) => `${tile.label} ${tile.figure}`),
    );

/** The post text for the Summary: every number with a value, in one line. */
const summaryCaption = (
    channel: string | null,
    tiles: ShareTile[],
    range: string,
): string => {
    const replacements = { metrics: summaryList(tiles), range };

    if (channel === null) {
        return trans('analytics.share.caption_summary_workspace', replacements);
    }

    return trans('analytics.share.caption_summary_channel', { ...replacements, channel });
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

/** The sum of the counts known so far, or null before any channel has one. */
const knownTotal = (counts: Map<string, number>): number | null => {
    if (counts.size === 0) {
        return null;
    }

    return sum([...counts.values()]);
};

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

        return { label: date.formatMonthDay(point.date), value: knownTotal(lastKnown) };
    });
};

/**
 * The id of the workspace channel a report row stands for, while it is still
 * connected: the report keys a connected channel by its id and gives it a live
 * status (`BuildWorkspaceAnalyticsReport::liveAccountsByKey`), and a channel
 * that is gone has none.
 */
const liveChannelId = (account: AccountIdentityData): string | null => {
    if (!account.status) {
        return null;
    }

    return account.social_account_key;
};

/** A channel's avatar served from the app, so the card can draw it; none for a channel no longer connected. */
const channelAvatar = (account: AccountIdentityData): string | null => {
    const id = liveChannelId(account);

    if (!id || !account.avatar_url) {
        return null;
    }

    return avatar.url(id);
};

/** One row per channel in its color, the way the page's per-channel charts draw it. */
const channelRows = <Account extends AccountIdentityData>(
    accounts: Account[],
    value: (account: Account) => number | null,
    colors: Record<string, string>,
): SharePoint[] =>
    accounts.map((account) => ({
        label: accountLabel(account),
        value: value(account),
        color: colors[account.social_account_key],
        channel: {
            platform: account.platform,
            avatar: channelAvatar(account),
            verified: account.verified_badge ?? null,
        },
    }));

const followersPoints = (
    followers: Followers,
    mode: FollowersChartMode,
    colors: Record<string, string>,
): SharePoint[] => {
    if (mode === 'line') {
        return followersOverTime(followers);
    }

    return channelRows(followers.accounts, FOLLOWERS_ROW_VALUES[mode], colors);
};

/** The followers gained or lost over the period, across the channels that report it. */
const followersChange = (followers: Followers): ShareChange | null => {
    const nets = followers.accounts
        .map((account) => account.net)
        .filter((net): net is number => net != null);

    if (nets.length === 0) {
        return null;
    }

    return countChange(sum(nets));
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
        points: followersPoints(followers, mode, colors),
        shape: FOLLOWERS_SHAPES[mode],
        caption: metricCaption(null, { metric: label, value: figure, range }),
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
        return countChange(current - previous);
    }

    if (previous === 0) {
        return null;
    }

    return percentChange(((current - previous) / Math.abs(previous)) * 100);
};

/** What the change is measured against: "vs 120 in the previous period". */
const seriesComparison = (
    metric: SeriesMetric,
    current: number | null | undefined,
    previous: number | null | undefined,
): string | null => {
    if (current == null || previous == null) {
        return null;
    }

    return trans('analytics.share.previous', {
        value: formatSeriesValue(previous, metric),
    });
};

const seriesShape = (metric: SeriesMetric): ShareShape => {
    if (LINE_METRICS.includes(metric)) {
        return 'line';
    }

    return 'bar';
};

/** One metric of a channel's series, drawn the way the page's chart draws it. */
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
        shape: seriesShape(metric),
        caption: metricCaption(channel, { metric: label, value: figure, range }),
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

/** The Posts card's columns over time, each split by channel in its color. */
const postsOverTime = (posts: Posts, colors: Record<string, string>): SharePoint[] =>
    posts.buckets.map((bucket) => ({
        label: axisBucketLabel(bucket, posts.resolution),
        value: bucket.total,
        segments: posts.accounts.map((account) => ({
            value: bucket.accounts[account.social_account_key] ?? 0,
            color: colors[account.social_account_key],
        })),
    }));

const postsPoints = (posts: Posts, mode: PostsChartMode, colors: Record<string, string>): SharePoint[] => {
    if (mode === 'stacked') {
        return postsOverTime(posts, colors);
    }

    return channelRows(posts.accounts, (account) => account.count, colors);
};

/** The posts sent over the period the way the page's Posts card draws them in `mode`. */
export const postsShareOption = (
    posts: Posts,
    range: string,
    mode: PostsChartMode,
    colors: Record<string, string>,
): ShareMetricOption => {
    const label = trans('analytics.dashboard.posts');
    const figure = formatSeriesValue(sum(posts.accounts.map((account) => account.count)), 'posts');

    return shareOption({
        key: 'posts',
        label,
        figure,
        points: postsPoints(posts, mode, colors),
        shape: POSTS_SHAPES[mode],
        caption: metricCaption(null, { metric: label, value: figure, range }),
    });
};
