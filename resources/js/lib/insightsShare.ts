import { trans } from 'laravel-vue-i18n';

import date from '@/date';
import { activeLocale } from '@/language';
import { axisBucketLabel, formatSeriesValue, seriesDelta } from '@/lib/channelMetrics';
import type {
    ShareChange,
    ShareChannel,
    ShareLine,
    ShareMetricOption,
    SharePoint,
    ShareShape,
    ShareTile,
} from '@/lib/insightsShareCard';
import { formatCountChange, formatNumberCompact, formatPercentChange } from '@/lib/utils';
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
    line: 'lines',
    growth: 'rows',
};

/** A per-channel view's rows: which number each channel shows, and how it reads. */
type ChannelRowsSpec<Account> = {
    value: (account: Account) => number | null;
    format: (value: number | null) => string;
};

/** What a channel's row shows in the per-channel modes: its followers, or its signed change. */
export const FOLLOWERS_ROWS: Record<
    Exclude<FollowersChartMode, 'line'>,
    ChannelRowsSpec<FollowerAccount>
> = {
    bar: { value: (account) => account.value, format: formatNumberCompact },
    growth: { value: (account) => account.growth, format: formatCountChange },
};

/** How the page's Posts card is drawn: per channel, or over time split by channel. */
export type PostsChartMode = 'bar' | 'stacked';

const POSTS_SHAPES: Record<PostsChartMode, ShareShape> = {
    bar: 'rows',
    stacked: 'stacked',
};

/** The Posts card's Bar view: each channel's posts sent. */
export const POSTS_ROWS: ChannelRowsSpec<Posts['accounts'][number]> = {
    value: (account) => account.count,
    format: formatNumberCompact,
};

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
    lines: [],
    ...option,
});

/** The channel as the card draws it: its avatar, network and verified mark. */
const shareChannel = (account: AccountIdentityData): ShareChannel => ({
    platform: account.platform,
    avatar: account.avatar_url ?? null,
    verified: account.verified_badge ?? null,
});

/** One row per channel in its color, the way the page's per-channel charts draw it. */
const channelRows = <Account extends AccountIdentityData>(
    accounts: Account[],
    spec: ChannelRowsSpec<Account>,
    colors: Record<string, string>,
): SharePoint[] =>
    accounts.map((account) => ({
        label: accountLabel(account),
        value: spec.value(account),
        figure: spec.format(spec.value(account)),
        color: colors[account.social_account_key],
        channel: shareChannel(account),
    }));

/** Each channel's count day by day, carrying its last known count over the days without a reading. */
const carriedForward = (followers: Followers, key: string): (number | null)[] => {
    let lastKnown: number | null = null;

    return followers.series.map((point) => {
        const value = point.accounts[key];

        if (typeof value === 'number') {
            lastKnown = value;
        }

        return lastKnown;
    });
};

/** One line per channel in its color, ending in its avatar and latest count, like the page's Line view. */
const followersLines = (followers: Followers, colors: Record<string, string>): ShareLine[] =>
    followers.accounts.map((account) => {
        const values = carriedForward(followers, account.social_account_key);

        return {
            color: colors[account.social_account_key],
            channel: shareChannel(account),
            figure: formatNumberCompact(values[values.length - 1]),
            values,
        };
    });

/** The Line view's dates, the points the lines run over. */
const followersDates = (followers: Followers): SharePoint[] =>
    followers.series.map((point) => ({ label: date.formatMonthDay(point.date), value: null }));

const followersPoints = (
    followers: Followers,
    mode: FollowersChartMode,
    colors: Record<string, string>,
): SharePoint[] => {
    if (mode === 'line') {
        return followersDates(followers);
    }

    return channelRows(followers.accounts, FOLLOWERS_ROWS[mode], colors);
};

/** The lines of the Line view; the other views draw rows. */
const followersLinesFor = (
    followers: Followers,
    mode: FollowersChartMode,
    colors: Record<string, string>,
): ShareLine[] => {
    if (mode !== 'line') {
        return [];
    }

    return followersLines(followers, colors);
};

/** The followers gained or lost over the period, across the channels that report it; null when none does. */
export const followersNet = (followers: Followers): number | null => {
    const nets = followers.accounts
        .map((account) => account.net)
        .filter((net): net is number => net != null);

    if (nets.length === 0) {
        return null;
    }

    return sum(nets);
};

const followersChange = (followers: Followers): ShareChange | null => {
    const net = followersNet(followers);

    if (net === null) {
        return null;
    }

    return countChange(net);
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
        lines: followersLinesFor(followers, mode, colors),
        shape: FOLLOWERS_SHAPES[mode],
        caption: metricCaption(null, { metric: label, value: figure, range }),
    });
};

/** A series metric the card can draw: growth rate has no figure of its own to show. */
export const isShareableSeriesMetric = (metric: SeriesMetric): boolean =>
    metric !== 'growth_rate';

/** A channel metric's change since the previous period, as the card draws it. */
const seriesChange = (
    metric: SeriesMetric,
    current: number | null | undefined,
    previous: number | null | undefined,
): ShareChange | null => {
    const delta = seriesDelta(metric, current, previous);

    if (!delta) {
        return null;
    }

    return { label: delta.label, direction: changeDirection(delta.value) };
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

    return channelRows(posts.accounts, POSTS_ROWS, colors);
};

/** The posts sent over the period the way the page's Posts card draws them in `mode`. */
export const postsShareOption = (
    posts: Posts,
    range: string,
    mode: PostsChartMode,
    colors: Record<string, string>,
): ShareMetricOption => {
    const label = trans('analytics.dashboard.posts');
    const figure = formatSeriesValue(posts.total, 'posts');

    return shareOption({
        key: 'posts',
        label,
        figure,
        points: postsPoints(posts, mode, colors),
        shape: POSTS_SHAPES[mode],
        caption: metricCaption(null, { metric: label, value: figure, range }),
    });
};
