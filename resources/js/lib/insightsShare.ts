/**
 * Builders for the share dialog. They run when the dialog opens, never in a
 * script-level computed, so every `trans()` here reads the loaded locale.
 */

import { trans } from 'laravel-vue-i18n';

import date from '@/date';
import { activeLocale } from '@/language';
import { accountLabel } from '@/lib/analyticsAccounts';
import {
    axisBucketLabel,
    bucketRangeLabel,
    type ChartMode,
    formatSeriesValue,
    type SeriesSpec,
    countDelta,
    type MetricDelta,
    seriesDelta,
    shownPeriod,
} from '@/lib/channelMetrics';
import {
    periodFigure,
    type ShareChannel,
    type ShareLine,
    type ShareMetricOption,
    type ShareSeries,
    type SharePoint,
    type ShareShape,
    type ShareTile,
} from '@/lib/shareCard';
import { formatCountChange, formatNumberCompact } from '@/lib/utils';
import type {
    AccountIdentityData,
    ChannelMetricSeries,
    FollowerAccount,
    FollowersChartMode,
    PostsChartMode,
    PublicationPeriod,
    SeriesMetric,
    WorkspaceAnalyticsReport,
} from '@/types/analytics';

type Followers = WorkspaceAnalyticsReport['followers'];

type Posts = WorkspaceAnalyticsReport['posts'];

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

const POSTS_SHAPES: Record<PostsChartMode, ShareShape> = {
    bar: 'rows',
    stacked: 'stacked',
};

/** The Posts card's Bar view: each channel's posts sent. */
export const POSTS_ROWS: ChannelRowsSpec<Posts['accounts'][number]> = {
    value: (account) => account.count,
    format: formatNumberCompact,
};

const sum = (values: number[]): number =>
    values.reduce((total, value) => total + value, 0);

/**
 * The period a share card names, with how many of the workspace's channels
 * it counts when the page is filtered to some ("2 – 31 Oct 2026 · 2 of 5
 * channels"), so the image and its post never pass a few channels off as all.
 */
export const shareRangeLabel = (range: string, shown: number | null, total: number | undefined): string => {
    if (shown === null || !total) {
        return range;
    }

    return `${range} · ${trans('analytics.share.channels_shown', { shown: String(shown), total: String(total) })}`;
};

/** The post text for one metric across the workspace's channels. */
const metricCaption = (replacements: { metric: string; value: string; range: string }): string =>
    trans('analytics.share.caption_workspace', replacements);

/** Labels joined the way the reader's language lists them: "Posts and Followers". */
const listOf = (labels: string[]): string =>
    new Intl.ListFormat(activeLocale.value, { type: 'conjunction' }).format(labels);

/** The Summary's numbers as one phrase in the reader's language: "Followers 1.2K, Posts 30 and Reach 5K". */
const summaryList = (tiles: CaptionNumber[]): string =>
    listOf(
        tiles
            .filter((tile): tile is CaptionNumber & { figure: string } => tile.figure !== null)
            .map((tile) => `${tile.label} ${tile.figure}`),
    );

/** A number the post text lists, by its label; one without a value is left out. */
type CaptionNumber = Pick<ShareTile, 'label' | 'figure'>;

/** The post text for the Summary: every number with a value, in one line. */
const summaryCaption = (
    channel: string | null,
    tiles: CaptionNumber[],
    range: string,
): string => {
    const replacements = { metrics: summaryList(tiles), range };

    if (channel === null) {
        return trans('analytics.share.caption_summary_workspace', replacements);
    }

    return trans('analytics.share.caption_summary_channel', { ...replacements, channel });
};

/** An option with nothing drawn but what it is given, in the shape it names. */
const shareOption = (
    option: Pick<ShareMetricOption, 'key' | 'label' | 'caption' | 'shape'> &
        Partial<ShareMetricOption>,
): ShareMetricOption => ({
    figure: '',
    change: null,
    points: [],
    tiles: [],
    lines: [],
    series: [],
    chartMode: 'current',
    ...option,
});

/**
 * The avatar's address for the card, which loads it with CORS. A CDN in front
 * of the storage host (Cloudflare) ignores `Vary: Origin`, so the copy the
 * page's `<img>` cached without CORS headers would refuse the card; a query of
 * its own gives the card a cache entry of its own, fetched with CORS.
 */
const corsAvatar = (url: string | null | undefined): string | null => {
    if (!url) {
        return null;
    }

    const address = new URL(url, window.location.origin);

    address.searchParams.set('cors', '1');

    return address.toString();
};

/** The channel as the card draws it: its avatar, network and verified mark. */
const shareChannel = (account: AccountIdentityData): ShareChannel => ({
    platform: account.platform,
    avatar: corsAvatar(account.avatar_url),
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

const followersChange = (followers: Followers): MetricDelta | null => {
    const net = followersNet(followers);

    if (net === null) {
        return null;
    }

    return countDelta(net);
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
        caption: metricCaption({ metric: label, value: figure, range }),
    });
};

/** One metric of the channel chart as the card draws it. */
const chartSeries = (series: ChannelMetricSeries, spec: SeriesSpec, label: string): ShareSeries => {
    const current = series.totals.current[spec.metric];
    const previous = series.totals.previous[spec.metric];

    return {
        metric: spec.metric,
        label,
        color: spec.color,
        kind: spec.type,
        axis: spec.axis,
        current: series.current.map((point) => point.values[spec.metric] ?? null),
        previous: series.previous.map((point) => point.values[spec.metric] ?? null),
        figure: formatSeriesValue(current, spec.metric),
        previousFigure: formatSeriesValue(previous, spec.metric),
        change: seriesDelta(series, spec.metric),
    };
};

/** A metric's figure for the post text over one period; null when it has no value there, so the text leaves it out. */
const captionFigure = (series: ChannelMetricSeries, metric: ShareSeries, period: PublicationPeriod): string | null => {
    if (series.totals[period][metric.metric] == null) {
        return null;
    }

    return periodFigure(metric, period);
};

/**
 * The channel's metrics chart exactly as the page shows it: the metrics it
 * draws, each as bars or a line on its axis, for the periods its toggle shows.
 */
export const channelChartShareOption = (
    series: ChannelMetricSeries,
    specs: SeriesSpec[],
    mode: ChartMode,
    labels: Record<SeriesMetric, string>,
    channel: string,
    range: string,
): ShareMetricOption => {
    const drawn = specs.map((spec) => chartSeries(series, spec, labels[spec.metric]));
    const period = shownPeriod(mode);
    const numbers = drawn.map((metric) => ({ label: metric.label, figure: captionFigure(series, metric, period) }));

    return shareOption({
        key: 'channel',
        label: listOf(drawn.map((metric) => metric.label)),
        points: series[period].map((point) => ({ label: axisBucketLabel(point, series.resolution), value: null })),
        shape: 'combo',
        series: drawn,
        chartMode: mode,
        caption: summaryCaption(channel, numbers, range),
    });
};

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
        label: bucketRangeLabel(bucket, posts.resolution),
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
        caption: metricCaption({ metric: label, value: figure, range }),
    });
};
