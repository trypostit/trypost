import { getPlatformLogo } from '@/composables/usePlatformLogo';
import date from '@/date';
import {
    AXIS_STEPS,
    axisDomain,
    axisTickLabels,
    chartPeriods,
    type ChartMode,
    type MetricDelta,
} from '@/lib/channelMetrics';
import { spreadLabels } from '@/lib/labelLayout';
import {
    canvasToFile,
    formatNumberCompact,
    MISSING_VALUE,
    resolveCssColor,
} from '@/lib/utils';
import type { PublicationPeriod, SeriesMetric } from '@/types/analytics';
import type { VerifiedBadge } from '@/types/social-account';

/**
 * The Insights share card: a chart or a headline number drawn on a canvas, to
 * download or post. Drawn rather than captured from the DOM, so it looks the
 * same in every browser and needs no dependency. Every image it draws loads
 * with CORS: one that does not would stop the canvas exporting.
 */

/** A line, columns or columns split by channel over time, one bar per channel, or the Summary's numbers. */
export type ShareShape = 'lines' | 'combo' | 'stacked' | 'rows' | 'tiles';


/** The channel a `rows` bar belongs to, drawn next to its name like the page's chart. */
export type ShareChannel = {
    platform: string;
    /** The stored avatar the report sends; the card draws the network's logo when it cannot load it with CORS. */
    avatar: string | null;
    verified: VerifiedBadge | null;
};

/** One channel's part of a `stacked` column, in the channel's color. */
type ShareSegment = { value: number; color: string };

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
type ShareImages = ReadonlyMap<string, HTMLImageElement>;

/** One number of the Summary, as its card prints it; null while it has no value. */
export type ShareTile = { label: string; figure: string | null; change: MetricDelta | null };

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

/** The light tints of the "What's New" covers, with the strong color the data is drawn in. */
export const SHARE_TINTS = [
    { key: 'violet', ground: '#d2c7fd', strong: '#6d28d9' },
    { key: 'purple', ground: '#ead9fb', strong: '#7e22ce' },
    { key: 'fuchsia', ground: '#f6dcfb', strong: '#a21caf' },
    { key: 'pink', ground: '#fce1ef', strong: '#db2777' },
    { key: 'red', ground: '#fde0e0', strong: '#dc2626' },
    { key: 'peach', ground: '#ffdbd6', strong: '#ea580c' },
    { key: 'orange', ground: '#ffe4c8', strong: '#c2410c' },
    { key: 'yellow', ground: '#fdefc3', strong: '#a16207' },
    { key: 'lime', ground: '#eaf7c8', strong: '#4d7c0f' },
    { key: 'green', ground: '#d9f1d1', strong: '#16a34a' },
    { key: 'teal', ground: '#ccf5ea', strong: '#0f766e' },
    { key: 'cyan', ground: '#cff4fc', strong: '#0e7490' },
    { key: 'blue', ground: '#def0ff', strong: '#2563eb' },
    { key: 'indigo', ground: '#dfe3fd', strong: '#4338ca' },
    { key: 'slate', ground: '#e3e8ef', strong: '#334155' },
    { key: 'neutral', ground: '#f4f1ec', strong: '#292928' },
] as const;

export type ShareTint = (typeof SHARE_TINTS)[number];

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

export const SHARE_CARD_SIZE = { width: 1200, height: 675 };

const SHARE_EXPORT_SCALE = 2;

type Point = { x: number; y: number };

type Box = Point & { width: number; height: number };

const INK = '#292928';
const MUTED = '#5a5a59';
const BORDER = 2.5;

const SANS = "Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif";

/** The app's heading face (`--font-heading`), for names and numbers. */
const HEADING = "Outfit, Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif";

const sans = (weight: number, size: number): string => `${weight} ${size}px ${SANS}`;

const heading = (weight: number, size: number): string => `${weight} ${size}px ${HEADING}`;

/**
 * The app's charts (`ChartContainer`, `ChannelMetricsChart`) at twice their
 * size, since the card is the page at 2x: a dashed `--border` grid, muted
 * ticks, bars without outline rounded at the data end, a 2px monotone line
 * with a dot on each point.
 */
const CHART = {
    grid: '#eae8e5',
    gridDash: [6, 6],
    gridWidth: 2,
    tickFont: sans(500, 22),
    barMaxWidth: 32,
    barMinWidth: 2,
    barPadding: 0.35,
    /** The page's bars round their data end 4px on 20px bars: the radius keeps that share of a bar's thickness. */
    barRoundness: 4 / 20,
    lineWidth: 4,
    dotRadius: 4,
    /** The page's value axes ask d3 for five ticks, its date axes for six. */
    valueTicks: 5,
    maxDates: 6,
};

/** `MetricChange`: `--success-text`, `--destructive-text` or muted, with Tabler's trending icons. */
const CHANGE_COLORS = { up: '#337046', down: '#7f0f00', flat: MUTED } as const;
const CHANGE_ICONS = {
    up: ['M3 17l6 -6l4 4l8 -8', 'M14 7l7 0l0 7'],
    down: ['M3 7l6 6l4 -4l8 8', 'M21 10l0 7l-7 0'],
    flat: ['M5 12l14 0'],
} as const;

/** The home hero's dot grid in the ground's corners, fading toward the middle. */
const DOTS = { gap: 24, radius: 1.4, opacity: 0.22, reach: 0.38 };

/** The white surface, inset from the tint like a cover's panel, over the signature's band. */
const PANEL = { x: 56, y: 48, radius: 24, padding: 48, band: 92 };

/** The name and what-and-when lines at the panel's top, as baselines from where they start. */
const IDENTITY = { top: 40, name: 34, detail: 76 };

/** The chart card's headline: the figure at the top right and its change under it. */
const HEADLINE = { reserve: 380, figure: 100, figureSize: 68, change: 116, changeSize: 26 };

/** The plot under the headline, with a gutter for the value ticks and room for the dates under it. */
const PLOT = { top: 212, bottom: 76, gutter: 72, tickGap: 18, dates: 40, dateGap: 24 };

/** d3's tick rule: the step is 10, 5, 2 or 1 times a power of ten, whichever lands closest to the asked count. */
const TICK_FACTORS: [number, number][] = [
    [Math.sqrt(50), 10],
    [Math.sqrt(10), 5],
    [Math.sqrt(2), 2],
    [0, 1],
];

/**
 * The page's Line view at 2x: its 112px rail (a 32px leader, the 20px avatar,
 * a 6px gap and the number in 12px medium), 24px between labels, 16px above
 * the plot the labels may use, 5 value ticks and up to 6 dates.
 */
const LINE_ENDS = { rail: 224, leader: 64, leaderWidth: 3, leaderInset: 8, leaderTip: 4, avatar: 40, avatarShare: 0.85, gap: 12, height: 48, fontSize: 24, fontShare: 0.6, headroom: 32 };

/** One bar per channel after a column of its name, number and avatar, as wide as they need; a name past `nameMax` is trimmed. */
const ROWS = {
    max: 12,
    nameMax: 320,
    figureGap: 32,
    labelGap: 24,
    axis: 44,
    valuesGap: 36,
    thickness: 28,
    thicknessShare: 0.55,
    label: { min: 14, max: 22, share: 0.55 },
    figure: { min: 16, max: 26, share: 0.6 },
};

/** A channel's avatar beside its name, at most the page's 24px at 2x, with the network badge at its corner. */
const AVATAR = { size: 48, fill: 0.8, radius: 0.25, gap: 14, badge: 0.5, verified: 0.42 };

/** `VerifiedBadge`'s colors, with the filled rosette Tabler draws it with. */
const VERIFIED_COLORS: Record<VerifiedBadge, string> = { blue: '#1d9bf0', business: '#e2b719', government: '#829aab' };
const VERIFIED_PATH =
    'M12.01 2.011a3.2 3.2 0 0 1 2.113 .797l.154 .145l.698 .698a1.2 1.2 0 0 0 .71 .341l.135 .008h1a3.2 3.2 0 0 1 3.195 3.018l.005 .182v1c0 .27 .092 .533 .258 .743l.09 .1l.697 .698a3.2 3.2 0 0 1 .147 4.382l-.145 .154l-.698 .698a1.2 1.2 0 0 0 -.341 .71l-.008 .135v1a3.2 3.2 0 0 1 -3.018 3.195l-.182 .005h-1a1.2 1.2 0 0 0 -.743 .258l-.1 .09l-.698 .697a3.2 3.2 0 0 1 -4.382 .147l-.154 -.145l-.698 -.698a1.2 1.2 0 0 0 -.71 -.341l-.135 -.008h-1a3.2 3.2 0 0 1 -3.195 -3.018l-.005 -.182v-1a1.2 1.2 0 0 0 -.258 -.743l-.09 -.1l-.697 -.698a3.2 3.2 0 0 1 -.147 -4.382l.145 -.154l.698 -.698a1.2 1.2 0 0 0 .341 -.71l.008 -.135v-1l.005 -.182a3.2 3.2 0 0 1 3.013 -3.013l.182 -.005h1a1.2 1.2 0 0 0 .743 -.258l.1 -.09l.698 -.697a3.2 3.2 0 0 1 2.269 -.944zm3.697 7.282a1 1 0 0 0 -1.414 0l-3.293 3.292l-1.293 -1.292l-.094 -.083a1 1 0 0 0 -1.32 1.497l2 2l.094 .083a1 1 0 0 0 1.32 -.083l4 -4l.083 -.094a1 1 0 0 0 -.083 -1.32z';

/** The Summary's grid under the identity; a tile is drawn for `tileHeight` and shrinks to fit. */
const SUMMARY = { top: 168, tileHeight: 185, inset: 36, crowdedInset: 24, crowdedColumns: 5, label: 50, labelSize: 24, figureGap: 6, changeGap: 16, changeSize: 24, changeBelow: 12, minTextShare: 0.7, figureOverhang: 2, crowdedFigure: 52, singleRowFigure: 84, figure: 64, crowdedFrom: 4 };

/** Canvas draws in the fallback face unless the page's fonts are loaded first. */
const loadFonts = async (): Promise<void> => {
    if (!('fonts' in document)) {
        return;
    }

    await Promise.all(
        [sans(500, 24), sans(600, 24), heading(500, 64), heading(600, 36)].map(
            (face) => document.fonts.load(face).catch(() => []),
        ),
    );
};

/** Each image once per page: the preview repaints on every tint or metric change. A failed load is forgotten, so the next card tries again. */
const loadedImages = new Map<string, Promise<HTMLImageElement | null>>();

/** An image ready to draw, or null when it cannot load, so a missing avatar never stops the card. */
const loadImage = (url: string): Promise<HTMLImageElement | null> => {
    const cached = loadedImages.get(url);

    if (cached) {
        return cached;
    }

    const image = new Image();

    image.crossOrigin = 'anonymous';
    image.src = url;

    const loading = image.decode().then(
        () => image,
        () => {
            loadedImages.delete(url);

            return null;
        },
    );

    loadedImages.set(url, loading);

    return loading;
};

/** A channel's network logo and, when it has one, its avatar. */
const channelImageUrls = (channel: ShareChannel): string[] => {
    const logo = getPlatformLogo(channel.platform);

    if (!channel.avatar) {
        return [logo];
    }

    return [channel.avatar, logo];
};

type LoadedImage = { url: string; image: HTMLImageElement };

/** The channels an option draws, as rows or as lines. */
const optionChannels = (option: Pick<ShareMetricOption, 'points' | 'lines'>): ShareChannel[] =>
    [...option.points.map((point) => point.channel), ...option.lines.map((line) => line.channel)].filter(
        (channel): channel is ShareChannel => channel !== undefined,
    );

/** Loads the fonts and every image the options draw, each once per page. */
const loadShareImages = async (options: Pick<ShareMetricOption, 'points' | 'lines'>[]): Promise<ShareImages> => {
    const urls = [...new Set(options.flatMap(optionChannels).flatMap(channelImageUrls))];
    const [, images] = await Promise.all([
        loadFonts(),
        Promise.all(urls.map(async (url) => ({ url, image: await loadImage(url) }))),
    ]);

    return new Map(
        images
            .filter((loaded): loaded is LoadedImage => loaded.image !== null)
            .map(({ url, image }) => [url, image]),
    );
};

export const prepareShareCard = (options: ShareCardOptions): Promise<ShareImages> => loadShareImages([options]);

/**
 * Warms the fonts and images a share card will draw before it is opened, so
 * the first preview paints at once; a later `prepareShareCard` finds them loaded.
 */
export const preloadShareCard = async (option: ShareMetricOption): Promise<void> => {
    await loadShareImages([option]);
};

type TextStyle = {
    font: string;
    color: string;
    align?: CanvasTextAlign;
    baseline?: CanvasTextBaseline;
};

/** Writes one line of text in its own style, so no drawing step inherits another's. */
const writeText = (
    context: CanvasRenderingContext2D,
    text: string,
    at: Point,
    { font, color, align = 'left', baseline = 'alphabetic' }: TextStyle,
): void => {
    context.font = font;
    context.fillStyle = color;
    context.textAlign = align;
    context.textBaseline = baseline;
    context.fillText(text, at.x, at.y);
};

/** The width of the widest of `texts` in `font`. */
const widest = (context: CanvasRenderingContext2D, texts: string[], font: string): number => {
    context.font = font;

    return Math.max(0, ...texts.map((text) => context.measureText(text).width));
};

/** The text cut with an ellipsis to fit `limit` in `font`. */
const ellipsise = (
    context: CanvasRenderingContext2D,
    text: string,
    font: string,
    limit: number,
): string => {
    context.font = font;

    if (context.measureText(text).width <= limit) {
        return text;
    }

    let kept = text;

    while (kept.length > 1 && context.measureText(`${kept}…`).width > limit) {
        kept = kept.slice(0, -1);
    }

    return `${kept}…`;
};

const strokeLine = (context: CanvasRenderingContext2D, from: Point, to: Point): void => {
    context.beginPath();
    context.moveTo(from.x, from.y);
    context.lineTo(to.x, to.y);
    context.stroke();
};

/** The covers' background: the flat tint with the dot grid in the top-right and bottom-left corners. */
const paintGround = (context: CanvasRenderingContext2D, tint: ShareTint): void => {
    const { width, height } = SHARE_CARD_SIZE;
    const reach = Math.hypot(width, height) * DOTS.reach;

    context.fillStyle = tint.ground;
    context.fillRect(0, 0, width, height);

    for (let y = DOTS.gap / 2; y < height; y += DOTS.gap) {
        for (let x = DOTS.gap / 2; x < width; x += DOTS.gap) {
            const corner = Math.min(Math.hypot(width - x, y), Math.hypot(x, height - y));
            const strength = 1 - corner / reach;

            if (strength <= 0) {
                continue;
            }

            context.fillStyle = `rgba(10, 10, 10, ${DOTS.opacity * strength})`;
            context.beginPath();
            context.arc(x, y, DOTS.radius, 0, Math.PI * 2);
            context.fill();
        }
    }
};

/** A white surface with the ink border and no shadow. */
const paintPanel = (context: CanvasRenderingContext2D): Box => {
    const { width, height } = SHARE_CARD_SIZE;
    const panel = {
        x: PANEL.x,
        y: PANEL.y,
        width: width - PANEL.x * 2,
        height: height - PANEL.y - PANEL.band,
    };

    context.beginPath();
    context.roundRect(panel.x, panel.y, panel.width, panel.height, PANEL.radius);
    context.fillStyle = '#ffffff';
    context.fill();
    context.strokeStyle = INK;
    context.lineWidth = BORDER;
    context.stroke();

    return panel;
};

/** The TryPost mark, the four quarters of `AppLogo.vue`, on a 410 grid. */
const paintLogo = (context: CanvasRenderingContext2D, at: Point, size: number): void => {
    const quarters: [string, string][] = [
        ['M410 109.736V410H128.863L410 109.736Z', '#3660E0'],
        ['M410 0H281.138L205 81.3178L410 300.264V0Z', '#855ED4'],
        ['M0 0V300.264L205 81.3178L281.138 0H0Z', '#FFCC33'],
        ['M0 109.736V410H128.862L205 328.682L0 109.736Z', '#F39200'],
    ];

    context.save();
    context.beginPath();
    context.arc(at.x + size / 2, at.y + size / 2, size / 2, 0, Math.PI * 2);
    context.clip();
    context.fillStyle = '#ffffff';
    context.fillRect(at.x, at.y, size, size);
    context.translate(at.x, at.y);
    context.scale(size / 410, size / 410);

    for (const [path, color] of quarters) {
        context.fillStyle = color;
        context.fill(new Path2D(path));
    }

    context.restore();
};

/** The signature's logo, the gap after it and its text size. */
const MARK = { logo: 32, gap: 12, size: 24 };

/** The signature, centred in the band under the panel: whoever sees the post sees where it came from. */
const paintMark = (context: CanvasRenderingContext2D, mark: string): void => {
    const font = sans(600, MARK.size);
    const centre = SHARE_CARD_SIZE.height - PANEL.band / 2;

    context.font = font;

    const start = (SHARE_CARD_SIZE.width - (MARK.logo + MARK.gap + context.measureText(mark).width)) / 2;

    paintLogo(context, { x: start, y: centre - MARK.logo / 2 }, MARK.logo);
    writeText(context, mark, { x: start + MARK.logo + MARK.gap, y: centre + 1 }, { font, color: INK, baseline: 'middle' });
};

/** The workspace or channel name, with what and when under it. */
const paintIdentity = (
    context: CanvasRenderingContext2D,
    subject: string,
    detail: string,
    at: Point,
    limit: number,
): void => {
    const nameFont = heading(600, 36);
    const detailFont = sans(500, 22);

    writeText(context, ellipsise(context, subject, nameFont, limit), { x: at.x, y: at.y + IDENTITY.name }, { font: nameFont, color: INK });
    writeText(context, ellipsise(context, detail, detailFont, limit), { x: at.x, y: at.y + IDENTITY.detail }, { font: detailFont, color: MUTED });
};

/** `MetricChange`'s trend icon: its size and the gap after it as shares of the label's, and its stroke on the 24px icon grid. */
const CHANGE_ICON = { scale: 1.15, gap: 0.3, stroke: 2.5, grid: 24 };

/** How far an anchor sits from a change's left edge, as a share of its width. */
const ALIGN_SHIFT = { left: 0, right: 1 } as const;

/** Which way a change points: up for a gain, down for a loss, flat when nothing moved. */
const changeDirection = (change: MetricDelta): keyof typeof CHANGE_COLORS => {
    if (change.value > 0) {
        return 'up';
    }

    if (change.value < 0) {
        return 'down';
    }

    return 'flat';
};

/** How wide a change is drawn at `size`: its trend icon, a gap, then its label. */
const changeWidth = (context: CanvasRenderingContext2D, change: MetricDelta, size: number): number => {
    context.font = sans(600, size);

    return size * (CHANGE_ICON.scale + CHANGE_ICON.gap) + context.measureText(change.label).width;
};

/** The change as the page's `MetricChange`: the trending icon and the label, in the tone's color. */
const paintChange = (
    context: CanvasRenderingContext2D,
    change: MetricDelta,
    anchor: Point,
    size: number,
    align: 'left' | 'right',
): void => {
    const direction = changeDirection(change);
    const color = CHANGE_COLORS[direction];
    const font = sans(600, size);
    const icon = size * CHANGE_ICON.scale;
    const gap = size * CHANGE_ICON.gap;
    const left = anchor.x - changeWidth(context, change, size) * ALIGN_SHIFT[align];

    context.save();
    context.translate(left, anchor.y);
    context.scale(icon / CHANGE_ICON.grid, icon / CHANGE_ICON.grid);
    context.strokeStyle = color;
    context.lineWidth = CHANGE_ICON.stroke;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    CHANGE_ICONS[direction].forEach((path) => context.stroke(new Path2D(path)));
    context.restore();

    writeText(context, change.label, { x: left + icon + gap, y: anchor.y + icon / 2 + 1 }, { font, color, baseline: 'middle' });
};

type ValueAxis = {
    ticks: number[];
    /** Where a value sits on the axis, from 0 at its start to 1 at its end. */
    at: (value: number) => number;
    /** Where the bars start: zero. */
    zero: number;
};

/**
 * A value axis like the page's unovis axes: the values' range with zero in
 * it, not rounded out, and d3's round ticks inside it.
 */
const valueAxis = (values: number[], tickCount: number): ValueAxis => {
    const low = Math.min(0, ...values);
    const high = Math.max(0, ...values);
    const at = (value: number): number => (value - low) / (high - low || 1);

    return { ticks: linearTicks(low, high, tickCount), at, zero: at(0) };
};

/** The page's dashed grid lines. */
const strokeGrid = (context: CanvasRenderingContext2D, lines: [Point, Point][]): void => {
    context.save();
    context.setLineDash(CHART.gridDash);
    context.lineWidth = CHART.gridWidth;
    context.strokeStyle = CHART.grid;
    lines.forEach((line) => strokeLine(context, ...line));
    context.restore();
};

/** A muted tick label of an axis. */
const writeTick = (context: CanvasRenderingContext2D, label: string, at: Point, style: Pick<TextStyle, 'align' | 'baseline'>): void => {
    writeText(context, label, at, { font: CHART.tickFont, color: MUTED, ...style });
};

/** The dashed value grid: one line per tick, with the tick's value at `label`. */
const paintGrid = (
    context: CanvasRenderingContext2D,
    ticks: number[],
    line: (value: number) => [Point, Point],
    label: (value: number) => Point,
    style: Pick<TextStyle, 'align' | 'baseline'>,
): void => {
    strokeGrid(context, ticks.map(line));
    ticks.forEach((value) => writeTick(context, formatNumberCompact(value), label(value), style));
};

/** The radius at a bar's low-coordinate edge and at its high one: only the data end is round. */
const dataEndRadii = (start: number, end: number, radius: number): [number, number] => {
    if (end > start) {
        return [0, radius];
    }

    return [radius, 0];
};

/**
 * A bar from the baseline (`start`) to its value (`end`) along its axis,
 * centred on `middle` across it and rounded only at the data end, like
 * unovis' `roundedCorners`.
 */
const fillBar = (
    context: CanvasRenderingContext2D,
    start: number,
    end: number,
    middle: number,
    thickness: number,
    horizontal: boolean,
): void => {
    const length = Math.abs(end - start);
    const radius = Math.min(thickness * CHART.barRoundness, length);
    const [low, high] = dataEndRadii(start, end, radius);

    context.beginPath();

    if (horizontal) {
        context.roundRect(Math.min(start, end), middle - thickness / 2, length, thickness, [low, high, high, low]);
    } else {
        context.roundRect(middle - thickness / 2, Math.min(start, end), thickness, length, [low, low, high, high]);
    }

    context.fill();
};

/** A middle point's tangent: flat at a peak or a dip, so the curve never overshoots, else the mean of its two slopes. */
const innerTangent = (before: number, after: number): number => {
    if (before * after <= 0) {
        return 0;
    }

    return (before + after) / 2;
};

/** The line through the points as a monotone cubic, d3's `curveMonotoneX` the page's charts use. */
const traceMonotone = (context: CanvasRenderingContext2D, points: Point[]): void => {
    context.beginPath();

    if (points.length === 0) {
        return;
    }

    context.moveTo(points[0].x, points[0].y);

    if (points.length < 3) {
        points.slice(1).forEach((point) => context.lineTo(point.x, point.y));

        return;
    }

    const slopes = points.slice(1).map((point, index) => (point.y - points[index].y) / (point.x - points[index].x));
    const tangents = points.map((_, index) => {
        if (index === 0) {
            return slopes[0];
        }

        if (index === points.length - 1) {
            return slopes[slopes.length - 1];
        }

        return innerTangent(slopes[index - 1], slopes[index]);
    });

    slopes.forEach((slope, index) => {
        if (slope === 0) {
            tangents[index] = 0;
            tangents[index + 1] = 0;

            return;
        }

        const a = tangents[index] / slope;
        const b = tangents[index + 1] / slope;
        const size = a * a + b * b;

        if (size > 9) {
            const scale = 3 / Math.sqrt(size);

            tangents[index] = scale * a * slope;
            tangents[index + 1] = scale * b * slope;
        }
    });

    points.slice(1).forEach((point, index) => {
        const previous = points[index];
        const third = (point.x - previous.x) / 3;

        context.bezierCurveTo(
            previous.x + third,
            previous.y + third * tangents[index],
            point.x - third,
            point.y - third * tangents[index + 1],
            point.x,
            point.y,
        );
    });
};

/** A channel's own color, the one the page draws it in, else the tint's strong color. */
const rowColor = (point: SharePoint, tint: ShareTint): string => {
    if (!point.color) {
        return tint.strong;
    }

    return point.color;
};

const avatarImage = (channel: ShareChannel, images: ShareImages): HTMLImageElement | undefined => {
    if (!channel.avatar) {
        return undefined;
    }

    return images.get(channel.avatar);
};

/** An image scaled to cover a square, cropped to its middle, inside the current clip. */
const drawCover = (context: CanvasRenderingContext2D, image: HTMLImageElement, at: Point, size: number): void => {
    const scale = size / Math.min(image.naturalWidth, image.naturalHeight);
    const width = image.naturalWidth * scale;
    const height = image.naturalHeight * scale;

    context.drawImage(image, at.x + (size - width) / 2, at.y + (size - height) / 2, width, height);
};

/** An image in a rounded square, on a white ground so a transparent logo reads on any tint. */
const paintTile = (context: CanvasRenderingContext2D, image: HTMLImageElement, at: Point, size: number): void => {
    context.save();
    context.beginPath();
    context.roundRect(at.x, at.y, size, size, size * AVATAR.radius);
    context.fillStyle = '#ffffff';
    context.fill();
    context.clip();
    drawCover(context, image, at, size);
    context.restore();
};

/** The network's logo on the avatar's bottom-end corner, ringed in white like `ChannelAvatar`'s badge. */
const paintNetworkBadge = (context: CanvasRenderingContext2D, logo: HTMLImageElement, avatar: Point, size: number): void => {
    const badge = size * AVATAR.badge;
    const ring = Math.max(1.5, badge * 0.12);
    const at = { x: avatar.x + size - badge * 0.66, y: avatar.y + size - badge * 0.8 };

    context.beginPath();
    context.roundRect(at.x - ring, at.y - ring, badge + ring * 2, badge + ring * 2, (badge + ring * 2) * AVATAR.radius);
    context.fillStyle = '#ffffff';
    context.fill();
    paintTile(context, logo, at, badge);
};

/** The verified rosette on the avatar's top-end corner, on a white disc like `VerifiedBadge`. */
const paintVerified = (context: CanvasRenderingContext2D, badge: VerifiedBadge, avatar: Point, size: number): void => {
    const mark = size * AVATAR.verified;
    const at = { x: avatar.x + size - mark * 0.7, y: avatar.y - mark * 0.3 };

    context.beginPath();
    context.arc(at.x + mark / 2, at.y + mark / 2, mark * 0.42, 0, Math.PI * 2);
    context.fillStyle = '#ffffff';
    context.fill();

    context.save();
    context.translate(at.x, at.y);
    context.scale(mark / 24, mark / 24);
    context.fillStyle = VERIFIED_COLORS[badge];
    context.fill(new Path2D(VERIFIED_PATH));
    context.restore();
};

/**
 * The channel as the page's chart shows it: its avatar with the network's logo
 * at the corner and the verified mark when it has one; a channel without an
 * avatar shows the network's logo in its place.
 */
const paintChannel = (
    context: CanvasRenderingContext2D,
    channel: ShareChannel,
    images: ShareImages,
    at: Point,
    size: number,
): void => {
    const logo = images.get(getPlatformLogo(channel.platform));
    const avatar = avatarImage(channel, images);

    if (avatar) {
        paintTile(context, avatar, at, size);

        if (logo) {
            paintNetworkBadge(context, logo, at, size);
        }
    } else if (logo) {
        paintTile(context, logo, at, size);
    }

    if (channel.verified) {
        paintVerified(context, channel.verified, at, size);
    }
};

/** The room a row's avatar takes before its bar: none when the row has no channel. */
const avatarSpace = (point: SharePoint, avatarSize: number): number => {
    if (!point.channel) {
        return 0;
    }

    return avatarSize + AVATAR.gap;
};

type RankedPoint = SharePoint & { index: number; value: number };

/** The channels with the largest values when they do not all fit, kept in the page's order. */
const rankedRows = (points: SharePoint[]): RankedPoint[] =>
    points
        .map((point, index) => ({ ...point, index }))
        .filter((point): point is RankedPoint => point.value !== null)
        .sort((a, b) => Math.abs(b.value) - Math.abs(a.value))
        .slice(0, ROWS.max)
        .sort((a, b) => a.index - b.index);

/** A row's text size: its share of the row's height, kept between the size's bounds. */
const rowTextSize = (size: { min: number; max: number; share: number }, rowHeight: number): number =>
    Math.min(size.max, Math.max(size.min, rowHeight * size.share));

/**
 * One horizontal bar per channel from zero, losses to the left, in each
 * channel's color, after its name, its number and its avatar in a fixed
 * column, like the page's Bar and Growth views.
 */
const paintRows = (context: CanvasRenderingContext2D, options: ShareCardOptions, images: ShareImages, area: Box): void => {
    const rows = rankedRows(options.points);
    const axis = valueAxis(rows.map((point) => point.value), CHART.valueTicks);
    const plotHeight = area.height - ROWS.axis;
    const rowHeight = plotHeight / Math.max(rows.length, 1);
    const thickness = Math.min(rowHeight * ROWS.thicknessShare, ROWS.thickness);
    const labelFont = sans(500, rowTextSize(ROWS.label, rowHeight));
    const figureFont = sans(600, rowTextSize(ROWS.figure, rowHeight));
    const avatarSize = Math.min(AVATAR.size, rowHeight * AVATAR.fill);
    const nameWidth = Math.min(ROWS.nameMax, widest(context, rows.map((point) => point.label), labelFont));
    const figureWidth = widest(context, rows.map((point) => point.figure ?? ''), figureFont);
    const channelWidth = Math.max(0, ...rows.map((point) => avatarSpace(point, avatarSize)));
    const column = nameWidth + ROWS.figureGap + figureWidth + channelWidth + ROWS.labelGap;
    const plot = { x: area.x + column, y: area.y, width: area.width - column, height: plotHeight };
    const x = (value: number): number => plot.x + axis.at(value) * plot.width;

    paintGrid(
        context,
        axis.ticks,
        (value) => [{ x: x(value), y: plot.y }, { x: x(value), y: plot.y + plot.height }],
        (value) => ({ x: x(value), y: plot.y + plot.height + ROWS.valuesGap }),
        { align: 'center' },
    );

    rows.forEach((point, row) => {
        const centre = plot.y + rowHeight * (row + 0.5);

        writeText(context, ellipsise(context, point.label, labelFont, nameWidth), { x: area.x, y: centre }, { font: labelFont, color: MUTED, baseline: 'middle' });

        if (point.figure) {
            writeText(context, point.figure, { x: area.x + nameWidth + ROWS.figureGap + figureWidth, y: centre }, { font: figureFont, color: INK, align: 'right', baseline: 'middle' });
        }

        if (point.channel) {
            paintChannel(context, point.channel, images, { x: plot.x - ROWS.labelGap - avatarSize, y: centre - avatarSize / 2 }, avatarSize);
        }

        if (point.value === 0) {
            return;
        }

        context.fillStyle = rowColor(point, options.tint);
        fillBar(context, plot.x + axis.zero * plot.width, x(point.value), centre, thickness, true);
    });
};

/** The subject and what and when on the left, the figure with its change on the right. */
const paintHeadline = (context: CanvasRenderingContext2D, options: ShareCardOptions, panel: Box): void => {
    const left = panel.x + PANEL.padding;
    const right = panel.x + panel.width - PANEL.padding;
    const limit = panel.width - PANEL.padding * 2 - HEADLINE.reserve;

    paintIdentity(context, options.subject, `${options.label} · ${options.range}`, { x: left, y: panel.y + IDENTITY.top }, limit);
    writeText(context, options.figure, { x: right, y: panel.y + HEADLINE.figure }, { font: heading(500, HEADLINE.figureSize), color: INK, align: 'right' });

    if (options.change) {
        paintChange(context, options.change, { x: right, y: panel.y + HEADLINE.change }, HEADLINE.changeSize, 'right');
    }
};

type Column = { middle: number; width: number };

/**
 * A column split by channel, bottom up in the page's order, with only its top
 * rounded like unovis' stacked bars.
 */
const paintStack = (
    context: CanvasRenderingContext2D,
    segments: ShareSegment[],
    column: Column,
    y: (value: number) => number,
): void => {
    const parts = segments.filter((segment) => segment.value > 0);
    let base = 0;

    parts.forEach((segment, index) => {
        const top = base + segment.value;

        context.fillStyle = segment.color;

        if (index === parts.length - 1) {
            fillBar(context, y(base), y(top), column.middle, column.width, false);
        } else {
            context.fillRect(column.middle - column.width / 2, y(top), column.width, y(base) - y(top));
        }

        base = top;
    });
};


/**
 * How wide each bar of a `slot` is: one bar like the page's, or, with both
 * periods, two bars side by side that fill the day with a small gap between
 * them instead of halving a single bar.
 */
const barWidth = (slot: number, bars: number): number =>
    Math.max(Math.min(barsRoom(slot, bars) / bars, CHART.barMaxWidth), CHART.barMinWidth);

/** The room a slot's bars share: a single bar keeps the page's padding, a pair fills the day but for the gap between them. */
const barsRoom = (slot: number, bars: number): number => {
    if (bars === 1) {
        return slot * (1 - CHART.barPadding);
    }

    return slot * (1 - COMBO.groupPadding) - COMBO.pairGap * (bars - 1);
};

/** One column per bucket from zero, split by channel in each channel's color. */
const paintBars = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    plot: Box,
    x: (index: number) => number,
    y: (value: number) => number,
): void => {
    const width = barWidth(plot.width / options.points.length, 1);

    options.points.forEach((point, index) => {
        if (point.segments && point.value) {
            paintStack(context, point.segments, { middle: x(index), width }, y);
        }
    });
};

/** Where the `index`-th of `count` points sits across a plot, the first at its start and the last at its end, like unovis' line. */
const spread = (index: number, count: number): number => {
    if (count <= 1) {
        return 0.5;
    }

    return index / (count - 1);
};

/** Round ticks inside [start, stop], about `count` of them, the way d3 (and so the page's axes) picks them. */
const linearTicks = (start: number, stop: number, count: number): number[] => {
    if (count <= 0 || stop < start) {
        return [];
    }

    if (stop === start) {
        return [start];
    }

    const raw = (stop - start) / count;
    const power = 10 ** Math.floor(Math.log10(raw));
    const error = raw / power;
    const step = power * TICK_FACTORS.find(([threshold]) => error >= threshold)![1];
    const first = Math.ceil(start / step);
    const last = Math.floor(stop / step);

    return Array.from({ length: last - first + 1 }, (_, index) => (first + index) * step);
};

/** The page's 2px monotone line through `points`, dashed when `dash` says so. */
const strokeCurve = (context: CanvasRenderingContext2D, points: Point[], color: string, dash: number[] = []): void => {
    context.save();
    context.strokeStyle = color;
    context.lineWidth = CHART.lineWidth;
    context.lineJoin = 'round';
    context.lineCap = 'round';
    context.setLineDash(dash);
    traceMonotone(context, points);
    context.stroke();
    context.restore();
};

/** The points of a line that have a value, where the plot draws them. */
const knownPoints = (values: (number | null)[], x: (index: number) => number, y: (value: number) => number): Point[] =>
    values.flatMap((value, index) => (value === null ? [] : [{ x: x(index), y: y(value) }]));

/** One channel's line in its color, with a dot on each known point. */
const strokeSeries = (context: CanvasRenderingContext2D, line: ShareLine, x: (index: number) => number, y: (value: number) => number): Point[] => {
    const known = knownPoints(line.values, x, y);
    const color = line.color;

    strokeCurve(context, known, color);

    context.fillStyle = color;
    known.forEach((point) => {
        context.beginPath();
        context.arc(point.x, point.y, CHART.dotRadius, 0, Math.PI * 2);
        context.fill();
    });

    return known;
};

/**
 * One line per channel, like the page's Line view: each ends in the channel's
 * avatar and latest number on the right, spread apart with a leader in the
 * line's color where lines end close together. The labels may use from just
 * above the plot down to the zero line; with many channels they shrink to fit.
 */
const paintLines = (context: CanvasRenderingContext2D, options: ShareCardOptions, images: ShareImages, area: Box): void => {
    const values = options.lines.flatMap((line) => line.values.filter((value): value is number => value !== null));

    if (values.length === 0) {
        return;
    }

    const plot = { ...area, width: area.width - LINE_ENDS.rail };
    const low = Math.min(...values);
    const high = Math.max(...values);
    const x = (index: number): number => plot.x + spread(index, options.points.length) * plot.width;
    const y = (value: number): number => plot.y + plot.height - ((value - low) / (high - low || 1)) * plot.height;
    const lineEnd = plot.x + plot.width;

    paintGrid(
        context,
        linearTicks(low, high, CHART.valueTicks),
        (value) => [{ x: plot.x, y: y(value) }, { x: lineEnd, y: y(value) }],
        (value) => ({ x: plot.x - PLOT.tickGap, y: y(value) }),
        { align: 'right', baseline: 'middle' },
    );

    paintDates(context, options.points, evenDates(options.points.length), x, plot);

    const ends = options.lines.flatMap((line) => {
        const known = strokeSeries(context, line, x, y);
        const last = known[known.length - 1];

        return last ? [{ line, lineY: last.y }] : [];
    });
    const room = { top: plot.y - LINE_ENDS.headroom, bottom: Math.min(y(0), plot.y + plot.height) };
    const step = Math.min(LINE_ENDS.height, (room.bottom - room.top) / Math.max(ends.length, 1));
    const avatarSize = Math.min(LINE_ENDS.avatar, step * LINE_ENDS.avatarShare);
    const font = sans(500, Math.min(LINE_ENDS.fontSize, step * LINE_ENDS.fontShare));
    const labels = spreadLabels(
        ends.map((end) => end.lineY),
        { gap: step, ...room },
    );

    ends.forEach(({ line, lineY }, position) => {
        const labelY = labels[position];
        const labelX = lineEnd + LINE_ENDS.leader;

        if (Math.abs(labelY - lineY) > 1) {
            context.strokeStyle = line.color;
            context.lineWidth = LINE_ENDS.leaderWidth;
            context.lineJoin = 'round';
            context.beginPath();
            context.moveTo(lineEnd + LINE_ENDS.leaderInset, lineY);
            context.lineTo(lineEnd + LINE_ENDS.leader / 2, lineY);
            context.lineTo(labelX - LINE_ENDS.leaderTip, labelY);
            context.stroke();
        }

        paintChannel(context, line.channel, images, { x: labelX, y: labelY - avatarSize / 2 }, avatarSize);
        writeText(context, line.figure, { x: labelX + avatarSize + LINE_ENDS.gap, y: labelY }, { font, color: INK, baseline: 'middle' });
    });
};

/**
 * The columns that get a date: every one, like the page's Posts chart, unless
 * the card is too narrow for them all, then as many as fit, evenly spread.
 */
const columnDates = (context: CanvasRenderingContext2D, points: SharePoint[], width: number): number[] => {
    const room = widest(context, points.map((point) => point.label), CHART.tickFont) + PLOT.dateGap;
    const fitting = Math.max(Math.floor(width / room), 1);

    if (points.length <= fitting) {
        return points.map((_, index) => index);
    }

    return linearTicks(0, points.length - 1, fitting);
};

/** Up to `CHART.maxDates` dates evenly spread over a line, like the page's date axes. */
const evenDates = (count: number): number[] => linearTicks(0, count - 1, Math.min(count, CHART.maxDates));

/** The dates of the points at `indexes`, under the plot. */
const paintDates = (
    context: CanvasRenderingContext2D,
    points: SharePoint[],
    indexes: number[],
    x: (index: number) => number,
    plot: Box,
): void => {
    indexes.forEach((index) => writeTick(context, points[index]?.label ?? '', { x: x(index), y: plot.y + plot.height + PLOT.dates }, { align: 'center' }));
};

/** The channel chart at 2x: its legend's label, figure and swatch, and its 16px bars. */
const COMBO = { legendGap: 56, labelSize: 22, figureSize: 52, figureLine: 52, swatchRise: 0.55, swatch: 18, swatchGap: 12, changeSize: 22, changeGap: 14, changeBelow: 14, rightGutter: 72, groupPadding: 0.1, pairGap: 4, dash: [10, 8] };


/** The page's hatch for a previous period's bars: stripes of the color over a light wash of it. */
const hatchPattern = (context: CanvasRenderingContext2D, color: string): CanvasPattern | string => {
    const tile = document.createElement('canvas');
    const size = 10;

    tile.width = size;
    tile.height = size;

    const stripes = tile.getContext('2d');

    if (!stripes) {
        return color;
    }

    stripes.fillStyle = color;
    stripes.globalAlpha = 0.12;
    stripes.fillRect(0, 0, size, size);
    stripes.globalAlpha = 1;
    stripes.fillRect(0, 0, size * 0.4, size);

    const pattern = context.createPattern(tile, 'repeat');

    if (!pattern) {
        return color;
    }

    pattern.setTransform(new DOMMatrix().rotate(45));

    return pattern;
};

/** The page's fill for a period's bars: the previous period always hatched. */
const periodFill = (context: CanvasRenderingContext2D, color: string, period: PublicationPeriod): CanvasPattern | string => {
    if (period === 'previous') {
        return hatchPattern(context, color);
    }

    return color;
};

/** The page's stroke for a period's line: the previous period always dashed. */
const periodDash = (period: PublicationPeriod): number[] => {
    if (period === 'previous') {
        return COMBO.dash;
    }

    return [];
};

/** A legend swatch like the page's: solid for this period, hatched (bars) or a dashed outline (lines) for the previous one. */
/** The legend swatch's corner, and the dashed outline that stands for a previous period's line. */
const SWATCH = { radius: 4, dash: [4, 3], outline: 3 };

const paintLegendSwatch = (context: CanvasRenderingContext2D, series: ShareSeries, color: string, period: PublicationPeriod, at: Point): void => {
    context.save();
    context.beginPath();
    context.roundRect(at.x, at.y, COMBO.swatch, COMBO.swatch, SWATCH.radius);

    if (period === 'previous' && series.kind === 'line') {
        context.setLineDash(SWATCH.dash);
        context.lineWidth = SWATCH.outline;
        context.strokeStyle = color;
        context.stroke();
        context.restore();

        return;
    }

    context.fillStyle = periodFill(context, color, period);
    context.fill();
    context.restore();
};

/** What a legend item prints in a chart mode: the previous period alone in Comparison, both periods in Both. */
const legendEntries = (series: ShareSeries, mode: ChartMode): { figure: string; period: PublicationPeriod }[] =>
    chartPeriods(mode).map((period) => ({ figure: periodFigure(series, period), period }));

/** A metric's figure over one period. */
export const periodFigure = (series: ShareSeries, period: PublicationPeriod): string => {
    if (period === 'previous') {
        return series.previousFigure;
    }

    return series.figure;
};

/** A legend figure's ink: the previous period's is muted next to the current one in Both, like the page's. */
const legendFigureColor = (period: PublicationPeriod, mode: ChartMode): string => {
    if (period === 'previous' && mode === 'both') {
        return MUTED;
    }

    return INK;
};

/** How wide a legend item's change is drawn; none without one. */
const legendChangeWidth = (context: CanvasRenderingContext2D, change: MetricDelta | null): number => {
    if (!change) {
        return 0;
    }

    return changeWidth(context, change, COMBO.changeSize);
};

/** The change a legend item shows: none in Comparison, which shows the previous period itself. */
const legendChange = (series: ShareSeries, mode: ChartMode): MetricDelta | null => {
    if (mode === 'previous') {
        return null;
    }

    return series.change;
};

/** The page's legend on the headline's right: each metric's name over its swatches and figures, its change under them. */
const paintComboLegend = (context: CanvasRenderingContext2D, options: ShareCardOptions, right: number, top: number): number => {
    const labelFont = sans(500, COMBO.labelSize);
    const figureFont = heading(500, COMBO.figureSize);
    let edge = right;

    [...options.series].reverse().forEach((series) => {
        const entries = legendEntries(series, options.chartMode);
        const change = legendChange(series, options.chartMode);

        context.font = figureFont;
        const entryWidths = entries.map((entry) => COMBO.swatch + COMBO.swatchGap + context.measureText(entry.figure).width);
        const figuresWidth = entryWidths.reduce((total, width) => total + width, 0) + COMBO.changeGap * (entries.length - 1);
        const changeRoom = legendChangeWidth(context, change);
        context.font = labelFont;
        const width = Math.max(context.measureText(series.label).width, figuresWidth, changeRoom);
        const left = edge - width;
        const baseline = top + COMBO.labelSize + COMBO.figureLine;
        const color = series.color;
        let cursor = left;

        writeText(context, series.label, { x: left, y: top + COMBO.labelSize }, { font: labelFont, color: MUTED });

        entries.forEach((entry, position) => {
            paintLegendSwatch(context, series, color, entry.period, { x: cursor, y: baseline - COMBO.figureSize * COMBO.swatchRise });
            writeText(context, entry.figure, { x: cursor + COMBO.swatch + COMBO.swatchGap, y: baseline }, { font: figureFont, color: legendFigureColor(entry.period, options.chartMode) });
            cursor += entryWidths[position] + COMBO.changeGap;
        });

        if (change) {
            paintChange(context, change, { x: left, y: baseline + COMBO.changeBelow }, COMBO.changeSize, 'left');
        }

        edge = left - COMBO.legendGap;
    });

    return right - edge - COMBO.legendGap;
};

/** The metrics drawn on an axis of the channel chart. */
const axisSeries = (options: ShareCardOptions, axis: ShareSeries['axis']): ShareSeries[] =>
    options.series.filter((series) => series.axis === axis);

/** An axis' domain over the periods shown, by the page's rule. */
const comboDomain = (series: ShareSeries[], periods: PublicationPeriod[]): { min: number; max: number } | null =>
    axisDomain(
        series.map((metric) => metric.metric),
        series.flatMap((metric) => periods.flatMap((period) => metric[period].filter((value): value is number => value !== null))),
    );

/** A period's values over the chart's days only, like the page, which lays the previous period over the current one's days. */
const shownValues = (series: ShareSeries, period: PublicationPeriod, days: number): (number | null)[] =>
    series[period].slice(0, days);

/** The room the right axis' labels take; none when no metric uses it. */
const rightAxisRoom = (domain: { min: number; max: number } | null): number => {
    if (domain) {
        return COMBO.rightGutter;
    }

    return 0;
};

/** Where an axis' tick labels sit: right-aligned before the plot on the left, left-aligned after it on the right. */
const AXIS_SIDES: Record<ShareSeries['axis'], { align: CanvasTextAlign; edge: (plot: Box) => number; direction: number }> = {
    left: { align: 'right', edge: (plot) => plot.x, direction: -1 },
    right: { align: 'left', edge: (plot) => plot.x + plot.width, direction: 1 },
};

/**
 * The channel chart as the page shows it: its metrics' bars and lines on a
 * left and a right axis, for the periods its toggle shows, the previous
 * period's bars hatched and its lines dashed, under the page's legend. The
 * legend names the metrics, so the line under the name keeps only the period.
 */
const paintCombo = (context: CanvasRenderingContext2D, options: ShareCardOptions, panel: Box): void => {
    const left = panel.x + PANEL.padding;
    const right = panel.x + panel.width - PANEL.padding;
    const legendWidth = paintComboLegend(context, options, right, panel.y + IDENTITY.top);

    paintIdentity(context, options.subject, options.range, { x: left, y: panel.y + IDENTITY.top }, right - left - legendWidth - COMBO.legendGap);

    const periods = chartPeriods(options.chartMode);
    const domains = {
        left: comboDomain(axisSeries(options, 'left'), periods),
        right: comboDomain(axisSeries(options, 'right'), periods),
    };
    const plot = {
        x: left + PLOT.gutter,
        y: panel.y + PLOT.top,
        width: right - left - PLOT.gutter - rightAxisRoom(domains.right),
        height: panel.height - PLOT.top - PLOT.bottom,
    };
    const count = Math.max(options.points.length, 1);
    const slot = plot.width / count;
    const x = (index: number): number => plot.x + slot * (index + 0.5);
    const y = (axis: 'left' | 'right', value: number): number => {
        const domain = domains[axis] ?? { min: 0, max: 1 };

        return plot.y + plot.height - ((value - domain.min) / (domain.max - domain.min || 1)) * plot.height;
    };
    const gridY = (step: number): number => plot.y + plot.height - (step / AXIS_STEPS) * plot.height;

    const steps = Array.from({ length: AXIS_STEPS + 1 }, (_, step) => step);

    strokeGrid(context, steps.map((step) => [{ x: plot.x, y: gridY(step) }, { x: plot.x + plot.width, y: gridY(step) }]));

    (['left', 'right'] as const).forEach((axis) => {
        const domain = domains[axis];

        if (!domain) {
            return;
        }

        const side = AXIS_SIDES[axis];
        const labels = axisTickLabels(domain, axisSeries(options, axis).map((series) => series.metric));

        labels.forEach((label, step) => {
            writeTick(context, label, { x: side.edge(plot) + side.direction * PLOT.tickGap, y: gridY(step) }, { align: side.align, baseline: 'middle' });
        });
    });

    const width = barWidth(slot, periods.length);
    const barStep = width + COMBO.pairGap * (periods.length - 1);

    options.series.filter((series) => series.kind === 'bar').forEach((series) => {
        const color = series.color;
        const zero = y(series.axis, Math.max(0, (domains[series.axis] ?? { min: 0 }).min));

        periods.forEach((period, position) => {
            context.fillStyle = periodFill(context, color, period);

            shownValues(series, period, count).forEach((value, index) => {
                if (value === null || value === 0) {
                    return;
                }

                const middle = x(index) + (position - (periods.length - 1) / 2) * barStep;

                fillBar(context, zero, y(series.axis, value), middle, width, false);
            });
        });
    });

    options.series.filter((series) => series.kind === 'line').forEach((series) => {
        periods.forEach((period) => {
            const known = knownPoints(shownValues(series, period, count), x, (value) => y(series.axis, value));

            strokeCurve(context, known, series.color, periodDash(period));
        });
    });

    paintDates(context, options.points, evenDates(count), x, plot);
};

/** The plot under the headline, past the gutter for the value ticks. */
const headlinePlot = (panel: Box): Box => ({
    x: panel.x + PANEL.padding + PLOT.gutter,
    y: panel.y + PLOT.top,
    width: panel.width - PANEL.padding * 2 - PLOT.gutter,
    height: panel.height - PLOT.top - PLOT.bottom,
});

/** The values of the points that have one. */
const pointValues = (points: SharePoint[]): number[] => points.flatMap((point) => (point.value === null ? [] : [point.value]));

/** The headline over one line per channel. */
const paintLinesCard = (context: CanvasRenderingContext2D, options: ShareCardOptions, images: ShareImages, panel: Box): void => {
    paintHeadline(context, options, panel);
    paintLines(context, options, images, headlinePlot(panel));
};

/** The headline over one bar per channel, which takes the gutter for its names and numbers. */
const paintRowsCard = (context: CanvasRenderingContext2D, options: ShareCardOptions, images: ShareImages, panel: Box): void => {
    paintHeadline(context, options, panel);

    if (pointValues(options.points).length === 0) {
        return;
    }

    const plot = headlinePlot(panel);

    paintRows(context, options, images, { x: plot.x - PLOT.gutter, y: plot.y, width: plot.width + PLOT.gutter, height: plot.height + PLOT.dates });
};

/** The headline over one column per bucket, split by channel. */
const paintStackedCard = (context: CanvasRenderingContext2D, options: ShareCardOptions, _images: ShareImages, panel: Box): void => {
    paintHeadline(context, options, panel);

    const values = pointValues(options.points);

    if (values.length === 0) {
        return;
    }

    const plot = headlinePlot(panel);
    const axis = valueAxis(values, CHART.valueTicks);
    const slot = plot.width / options.points.length;
    const x = (index: number): number => plot.x + slot * (index + 0.5);
    const y = (value: number): number => plot.y + plot.height - axis.at(value) * plot.height;

    paintGrid(
        context,
        axis.ticks,
        (value) => [{ x: plot.x, y: y(value) }, { x: plot.x + plot.width, y: y(value) }],
        (value) => ({ x: plot.x - PLOT.tickGap, y: y(value) }),
        { align: 'right', baseline: 'middle' },
    );
    paintBars(context, options, plot, x, y);
    paintDates(context, options.points, columnDates(context, options.points, plot.width), x, plot);
};

/** A tile's number in ink, or muted when the metric has no value yet. */
const tileFigureColor = (tile: ShareTile): string => {
    if (tile.figure === null) {
        return MUTED;
    }

    return INK;
};

/** How many columns the Summary's numbers take, like the page's grid. */
const tileColumns = (count: number): number => {
    if (count <= 3) {
        return Math.max(count, 1);
    }

    if (count === 4) {
        return 2;
    }

    if (count <= 6) {
        return 3;
    }

    if (count <= 8) {
        return 4;
    }

    return 5;
};

/** The space inside a tile's border: tighter in the five-column grid, whose tiles are the narrowest. */
const tileInset = (columns: number): number => {
    if (columns >= SUMMARY.crowdedColumns) {
        return SUMMARY.crowdedInset;
    }

    return SUMMARY.inset;
};

/** A tile's figure size before it shrinks to fit: smaller in a crowded grid, largest in a single row. */
const tileFigureSize = (columns: number, rows: number): number => {
    if (columns >= SUMMARY.crowdedFrom) {
        return SUMMARY.crowdedFigure;
    }

    if (rows === 1) {
        return SUMMARY.singleRowFigure;
    }

    return SUMMARY.figure;
};

/** How many tiles a row of the Summary holds: full rows, and the rest stretched over the last one so no tile is left empty. */
const tilesInRow = (row: number, rows: number, columns: number, count: number): number => {
    if (row < rows - 1) {
        return columns;
    }

    return count - columns * (rows - 1);
};

/**
 * Every number of the Summary, split by ink rules like a cover's settings
 * panel. More rows shrink every tile instead of dropping numbers, and every
 * label shares one size, the one they all fit in the narrowest tile.
 */
const paintSummary = (context: CanvasRenderingContext2D, options: ShareCardOptions, panel: Box): void => {
    paintIdentity(
        context,
        options.subject,
        options.range,
        { x: panel.x + PANEL.padding, y: panel.y + IDENTITY.top },
        panel.width - PANEL.padding * 2,
    );

    const tiles = options.tiles;
    const columns = tileColumns(tiles.length);
    const rows = Math.ceil(tiles.length / columns);
    const grid = { x: panel.x, y: panel.y + SUMMARY.top, width: panel.width, height: panel.height - SUMMARY.top };
    const tileHeight = grid.height / Math.max(rows, 1);
    const fit = Math.min(1, tileHeight / SUMMARY.tileHeight);
    const figureSize = tileFigureSize(columns, rows) * fit;
    const rowSize = (row: number): number => tilesInRow(row, rows, columns, tiles.length);
    const inset = tileInset(columns) * fit;
    const tileWidth = (index: number): number => grid.width / rowSize(Math.floor(index / columns));
    const tileLimit = (index: number): number => tileWidth(index) - inset * 2;
    const labelSize = Math.min(
        ...tiles.map((tile) => fittingSize(context, tile.label, (size) => sans(500, size), SUMMARY.labelSize * fit, grid.width / columns - inset * 2)),
    );
    const labelFont = sans(500, labelSize);
    const changeSize = SUMMARY.changeSize * fit;
    const under = changesUnderFigures(context, tiles, heading(500, figureSize), changeSize, SUMMARY.changeGap * fit, tileLimit);

    context.strokeStyle = INK;
    context.lineWidth = BORDER;

    for (let row = 0; row < rows; row += 1) {
        const top = grid.y + tileHeight * row;
        const width = grid.width / rowSize(row);

        strokeLine(context, { x: grid.x, y: top }, { x: grid.x + grid.width, y: top });

        for (let column = 1; column < rowSize(row); column += 1) {
            strokeLine(context, { x: grid.x + width * column, y: top }, { x: grid.x + width * column, y: top + tileHeight });
        }
    }

    tiles.forEach((tile, index) => {
        const row = Math.floor(index / columns);
        const left = grid.x + tileWidth(index) * (index % columns) + inset;
        const limit = tileLimit(index);
        const labelLine = grid.y + tileHeight * row + SUMMARY.label * fit;
        const figureText = tile.figure ?? MISSING_VALUE;
        const figureFont = heading(500, fittingSize(context, figureText, (size) => heading(500, size), figureSize, limit));
        const baseline = labelLine + figureSize + SUMMARY.figureGap * fit;

        writeText(context, ellipsise(context, tile.label, labelFont, limit), { x: left, y: labelLine }, { font: labelFont, color: MUTED });
        writeText(context, figureText, { x: left - SUMMARY.figureOverhang * fit, y: baseline }, {
            font: figureFont,
            color: tileFigureColor(tile),
        });

        if (tile.change) {
            paintTileChange(context, tile.change, { x: left, y: baseline }, { figureFont, figure: figureText, changeSize, fit, under });
        }
    });
};

/** The size, down to `SUMMARY.minTextShare` of `size`, at which `text` fits `limit` in the font `fontAt` gives. */
const fittingSize = (
    context: CanvasRenderingContext2D,
    text: string,
    fontAt: (size: number) => string,
    size: number,
    limit: number,
): number => {
    context.font = fontAt(size);
    const width = context.measureText(text).width;

    if (width <= limit) {
        return size;
    }

    return Math.max(size * SUMMARY.minTextShare, (size * limit) / width);
};

/**
 * Whether the changes go under the numbers: as soon as one tile has no room
 * for its change beside its number, every tile stacks them, as the page's
 * half-width cards do on a phone, so the grid reads the same throughout.
 */
const changesUnderFigures = (
    context: CanvasRenderingContext2D,
    tiles: ShareTile[],
    figureFont: string,
    changeSize: number,
    gap: number,
    limit: (index: number) => number,
): boolean =>
    tiles.some((tile, index) => {
        if (!tile.change) {
            return false;
        }

        context.font = figureFont;

        return context.measureText(tile.figure ?? MISSING_VALUE).width + gap + changeWidth(context, tile.change, changeSize) > limit(index);
    });

/** A tile's change: beside its number with its icon on the number's baseline, like the page's cards, or under it. */
const paintTileChange = (
    context: CanvasRenderingContext2D,
    change: MetricDelta,
    figureAt: Point,
    tile: { figureFont: string; figure: string; changeSize: number; fit: number; under: boolean },
): void => {
    if (tile.under) {
        paintChange(context, change, { x: figureAt.x, y: figureAt.y + SUMMARY.changeBelow * tile.fit }, tile.changeSize, 'left');

        return;
    }

    context.font = tile.figureFont;
    const besideFigure = figureAt.x - SUMMARY.figureOverhang * tile.fit + context.measureText(tile.figure).width + SUMMARY.changeGap * tile.fit;

    paintChange(context, change, { x: besideFigure, y: figureAt.y - tile.changeSize * CHANGE_ICON.scale }, tile.changeSize, 'left');
};

/** What draws each shape inside the panel. */
const PAINTERS: Record<ShareShape, (context: CanvasRenderingContext2D, options: ShareCardOptions, images: ShareImages, panel: Box) => void> = {
    lines: paintLinesCard,
    rows: paintRowsCard,
    stacked: paintStackedCard,
    combo: (context, options, _images, panel) => paintCombo(context, options, panel),
    tiles: (context, options, _images, panel) => paintSummary(context, options, panel),
};

/** A CSS color resolved once per paint: a column per day times every channel is many segments. */
const colorResolver = (): ((color: string) => string) => {
    const resolved = new Map<string, string>();

    return (color) => {
        const known = resolved.get(color) ?? resolveCssColor(color);

        resolved.set(color, known);

        return known;
    };
};

/**
 * The card with every color as the browser computes it: a canvas cannot read
 * CSS variables, so the theme's colors are resolved once before painting.
 */
const withResolvedColors = (options: ShareCardOptions): ShareCardOptions => {
    const resolve = colorResolver();

    return {
        ...options,
        points: options.points.map((point) => ({
            ...point,
            color: point.color && resolve(point.color),
            segments: point.segments?.map((segment) => ({ ...segment, color: resolve(segment.color) })),
        })),
        lines: options.lines.map((line) => ({ ...line, color: resolve(line.color) })),
        series: options.series.map((series) => ({ ...series, color: resolve(series.color) })),
    };
};

/** Paints the card; `images` comes from `prepareShareCard`, since a canvas only draws images already loaded. */
export const drawShareCard = (
    canvas: HTMLCanvasElement,
    options: ShareCardOptions,
    images: ShareImages,
    scale = SHARE_EXPORT_SCALE,
): void => {
    canvas.width = SHARE_CARD_SIZE.width * scale;
    canvas.height = SHARE_CARD_SIZE.height * scale;

    const context = canvas.getContext('2d');

    if (context === null) {
        return;
    }

    context.setTransform(scale, 0, 0, scale, 0, 0);
    paintGround(context, options.tint);

    const panel = paintPanel(context);

    PAINTERS[options.shape](context, withResolvedColors(options), images, panel);
    paintMark(context, options.mark);
};

/** The card at download resolution, off screen, as a PNG named after its metric and today: `trypost-followers-2026-10-31.png`. */
export const shareCardFile = async (options: ShareCardOptions): Promise<File> => {
    const canvas = document.createElement('canvas');
    const name = `trypost-${options.key.replaceAll('_', '-')}-${date.userNow().format('YYYY-MM-DD')}.png`;

    drawShareCard(canvas, options, await prepareShareCard(options));

    return canvasToFile(canvas, name, 'image/png');
};
