import { getPlatformLogo } from '@/composables/usePlatformLogo';
import date from '@/date';
import {
    canvasToFile,
    formatNumberCompact,
    resolveCssColor,
} from '@/lib/utils';
import type { SeriesMetric } from '@/types/analytics';
import type { VerifiedBadge } from '@/types/social-account';

/**
 * The Insights share card: a chart or a headline number drawn on a canvas, to
 * download or post. Drawn rather than captured from the DOM, so it looks the
 * same in every browser and needs no dependency. It holds only vectors and
 * text: remote avatars are left out on purpose, because a network's CDN sends
 * no CORS header and one such image would stop the canvas exporting.
 */

/** A line, columns or columns split by channel over time, one bar per channel, or the Summary's numbers. */
export type ShareShape = 'line' | 'bar' | 'stacked' | 'rows' | 'tiles';

export type ShareChange = { label: string; direction: 'up' | 'down' | 'flat' };

/** The channel a `rows` bar belongs to, drawn next to its name like the page's chart. */
export type ShareChannel = {
    platform: string;
    /** Served from the app's own origin, so drawing it keeps the canvas exportable. */
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

/** The images a card draws, loaded before painting: a canvas only draws images already loaded. */
export type ShareImages = ReadonlyMap<string, HTMLImageElement>;

/** One number of the Summary, as its card prints it; null while it has no value. */
export type ShareTile = { label: string; figure: string | null; change: ShareChange | null };

/** What a share option draws: one metric of a series, or the whole Summary. */
export type ShareMetricKey = SeriesMetric | 'summary';

/** One metric a share dialog can draw, with the post text it opens the composer with. */
export type ShareMetricOption = {
    key: ShareMetricKey;
    label: string;
    figure: string;
    change: ShareChange | null;
    /** What the change is measured against: "vs 120 in the previous period". */
    comparison: string | null;
    points: SharePoint[];
    shape: ShareShape;
    /** Every number of the Summary, for the summary card. */
    tiles: ShareTile[];
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

export const SHARE_EXPORT_SCALE = 2;

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
    barPadding: 0.35,
    /** The page's bars round their data end 4px on 20px bars: the radius keeps that share of a bar's thickness. */
    barRoundness: 4 / 20,
    lineWidth: 4,
    dotRadius: 4,
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

/** The chart card's headline: the figure at the top right, its change and comparison under it. */
const HEADLINE = { reserve: 380, figure: 100, change: 116, changeSize: 26, comparison: 136, comparisonUnderChange: 176 };

/** The plot under the headline, with a gutter for the value ticks and room for the dates under it. */
const PLOT = { top: 212, bottom: 76, gutter: 72, tickGap: 18, dates: 40, maxDates: 5 };

/** One bar per channel: the avatar and name in a column on the left, the values under the bars. */
const ROWS = { max: 12, labelWidth: 420, figureWidth: 120, labelGap: 24, axis: 44, valuesGap: 36, thickness: 28 };

/** A channel's avatar beside its name, at most the page's 24px at 2x, with the network badge at its corner. */
const AVATAR = { size: 48, fill: 0.8, radius: 0.25, gap: 14, badge: 0.5, verified: 0.42 };

/** `VerifiedBadge`'s colors, with the filled rosette Tabler draws it with. */
const VERIFIED_COLORS: Record<VerifiedBadge, string> = { blue: '#1d9bf0', business: '#e2b719', government: '#829aab' };
const VERIFIED_PATH =
    'M12.01 2.011a3.2 3.2 0 0 1 2.113 .797l.154 .145l.698 .698a1.2 1.2 0 0 0 .71 .341l.135 .008h1a3.2 3.2 0 0 1 3.195 3.018l.005 .182v1c0 .27 .092 .533 .258 .743l.09 .1l.697 .698a3.2 3.2 0 0 1 .147 4.382l-.145 .154l-.698 .698a1.2 1.2 0 0 0 -.341 .71l-.008 .135v1a3.2 3.2 0 0 1 -3.018 3.195l-.182 .005h-1a1.2 1.2 0 0 0 -.743 .258l-.1 .09l-.698 .697a3.2 3.2 0 0 1 -4.382 .147l-.154 -.145l-.698 -.698a1.2 1.2 0 0 0 -.71 -.341l-.135 -.008h-1a3.2 3.2 0 0 1 -3.195 -3.018l-.005 -.182v-1a1.2 1.2 0 0 0 -.258 -.743l-.09 -.1l-.697 -.698a3.2 3.2 0 0 1 -.147 -4.382l.145 -.154l.698 -.698a1.2 1.2 0 0 0 .341 -.71l.008 -.135v-1l.005 -.182a3.2 3.2 0 0 1 3.013 -3.013l.182 -.005h1a1.2 1.2 0 0 0 .743 -.258l.1 -.09l.698 -.697a3.2 3.2 0 0 1 2.269 -.944zm3.697 7.282a1 1 0 0 0 -1.414 0l-3.293 3.292l-1.293 -1.292l-.094 -.083a1 1 0 0 0 -1.32 1.497l2 2l.094 .083a1 1 0 0 0 1.32 -.083l4 -4l.083 -.094a1 1 0 0 0 -.083 -1.32z';

/** The Summary's grid under the identity; a tile is drawn for `tileHeight` and shrinks to fit. */
const SUMMARY = { top: 168, tileHeight: 185, inset: 36, label: 50, labelSize: 24, figureGap: 6, changeGap: 22, changeSize: 24 };

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

/** Each image once per page: the preview repaints on every tint or metric change. */
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
        () => null,
    );

    loadedImages.set(url, loading);

    return loading;
};

/** A channel's avatar, when it has one, and its network's logo. */
const channelImageUrls = (channel: ShareChannel): string[] => {
    const logo = getPlatformLogo(channel.platform);

    if (!channel.avatar) {
        return [logo];
    }

    return [channel.avatar, logo];
};

type LoadedImage = { url: string; image: HTMLImageElement };

/** Waits for the fonts and loads the images a card draws, before it is painted. */
export const prepareShareCard = async (options: ShareCardOptions): Promise<ShareImages> => {
    const channels = options.points
        .map((point) => point.channel)
        .filter((channel): channel is ShareChannel => channel !== undefined);
    const urls = [...new Set(channels.flatMap(channelImageUrls))];
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

/** The signature, centred in the band under the panel: whoever sees the post sees where it came from. */
const paintMark = (context: CanvasRenderingContext2D, mark: string): void => {
    const logo = 32;
    const gap = 12;
    const font = sans(600, 24);
    const centre = SHARE_CARD_SIZE.height - PANEL.band / 2;

    context.font = font;

    const start = (SHARE_CARD_SIZE.width - (logo + gap + context.measureText(mark).width)) / 2;

    paintLogo(context, { x: start, y: centre - logo / 2 }, logo);
    writeText(context, mark, { x: start + logo + gap, y: centre + 1 }, { font, color: INK, baseline: 'middle' });
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

/** The change as the page's `MetricChange`: the trending icon and the label, in the tone's color. */
const paintChange = (
    context: CanvasRenderingContext2D,
    change: ShareChange,
    anchor: Point,
    size: number,
    align: 'left' | 'right',
): void => {
    const color = CHANGE_COLORS[change.direction];
    const font = sans(600, size);
    const icon = size * 1.15;
    const gap = size * 0.3;

    context.font = font;

    const width = icon + gap + context.measureText(change.label).width;
    const left = align === 'right' ? anchor.x - width : anchor.x;

    context.save();
    context.translate(left, anchor.y);
    context.scale(icon / 24, icon / 24);
    context.strokeStyle = color;
    context.lineWidth = 2.5;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    CHANGE_ICONS[change.direction].forEach((path) => context.stroke(new Path2D(path)));
    context.restore();

    writeText(context, change.label, { x: left + icon + gap, y: anchor.y + icon / 2 + 1 }, { font, color, baseline: 'middle' });
};

const NICE_STEPS = [1, 2, 2.5, 5, 10];

/** A round step that splits `span` into about `count` parts: 1, 2, 2.5 or 5 times a power of ten. */
const niceStep = (span: number, count: number): number => {
    const raw = span / count;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const normalised = raw / magnitude;

    return (NICE_STEPS.find((step) => normalised <= step) ?? 10) * magnitude;
};

/**
 * Bars start at zero; a line of large, steady values (followers) gets a range
 * around its own values so its movement stays visible, like the page's chart.
 */
const domain = (values: number[], shape: ShareShape): [number, number] => {
    if (values.length === 0) {
        return [0, 1];
    }

    const low = Math.min(...values);
    const high = Math.max(...values);

    if (shape !== 'line' || (low >= 0 && low <= high - low)) {
        const bottom = Math.min(0, low);
        const top = Math.max(0, high);

        return [bottom, top === bottom ? 1 : top];
    }

    const padding = Math.max((high - low) * 0.15, 1);

    return [low - padding, high + padding];
};

type ValueAxis = {
    ticks: number[];
    /** Where a value sits on the axis, from 0 at its bottom to 1 at its top. */
    at: (value: number) => number;
    /** Where the bars start: zero, or the bottom when every value is above it. */
    zero: number;
};

/** A chart's value axis with round ticks from its bottom to its top. */
const valueAxis = (values: number[], shape: ShareShape): ValueAxis => {
    const [low, high] = domain(values, shape);
    const step = Math.max(1, niceStep(high - low || 1, 4));
    const bottom = Math.floor(low / step) * step;
    const top = Math.ceil(high / step) * step;
    const at = (value: number): number => (value - bottom) / (top - bottom || 1);

    return {
        ticks: Array.from({ length: Math.round((top - bottom) / step) + 1 }, (_, index) => bottom + step * index),
        at,
        zero: at(Math.max(0, bottom)),
    };
};

/** The dashed value grid: one line per tick, with the tick's value at `label`. */
const paintGrid = (
    context: CanvasRenderingContext2D,
    ticks: number[],
    line: (value: number) => [Point, Point],
    label: (value: number) => Point,
    style: Pick<TextStyle, 'align' | 'baseline'>,
): void => {
    context.save();
    context.setLineDash(CHART.gridDash);
    context.lineWidth = CHART.gridWidth;
    context.strokeStyle = CHART.grid;

    ticks.forEach((value) => {
        strokeLine(context, ...line(value));
        writeText(context, formatNumberCompact(value), label(value), { font: CHART.tickFont, color: MUTED, ...style });
    });

    context.restore();
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
    /** The radius at the bar's low-coordinate edge and at its high one: only the data end is round. */
    const [low, high] = end > start ? [0, radius] : [radius, 0];

    context.beginPath();

    if (horizontal) {
        context.roundRect(Math.min(start, end), middle - thickness / 2, length, thickness, [low, high, high, low]);
    } else {
        context.roundRect(middle - thickness / 2, Math.min(start, end), thickness, length, [low, low, high, high]);
    }

    context.fill();
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

        return slopes[index - 1] * slopes[index] <= 0 ? 0 : (slopes[index - 1] + slopes[index]) / 2;
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

    return resolveCssColor(point.color);
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

/**
 * One horizontal bar per channel from zero, losses to the left, in each
 * channel's color, after its name, its number and its avatar in a fixed
 * column, like the page's Bar and Growth views.
 */
const paintRows = (context: CanvasRenderingContext2D, options: ShareCardOptions, images: ShareImages, area: Box): void => {
    const rows = rankedRows(options.points);
    const axis = valueAxis(rows.map((point) => point.value), 'bar');
    const plot = {
        x: area.x + ROWS.labelWidth,
        y: area.y,
        width: area.width - ROWS.labelWidth,
        height: area.height - ROWS.axis,
    };
    const x = (value: number): number => plot.x + axis.at(value) * plot.width;
    const rowHeight = plot.height / Math.max(rows.length, 1);
    const thickness = Math.min(rowHeight * 0.55, ROWS.thickness);
    const labelFont = sans(500, Math.min(22, Math.max(14, rowHeight * 0.55)));
    const figureFont = sans(600, Math.min(26, Math.max(16, rowHeight * 0.6)));
    const avatarSize = Math.min(AVATAR.size, rowHeight * AVATAR.fill);

    paintGrid(
        context,
        axis.ticks,
        (value) => [{ x: x(value), y: plot.y }, { x: x(value), y: plot.y + plot.height }],
        (value) => ({ x: x(value), y: plot.y + plot.height + ROWS.valuesGap }),
        { align: 'center' },
    );

    rows.forEach((point, row) => {
        const centre = plot.y + rowHeight * (row + 0.5);
        const channelSpace = avatarSpace(point, avatarSize);
        const figureRight = plot.x - ROWS.labelGap - channelSpace;
        const label = ellipsise(context, point.label, labelFont, ROWS.labelWidth - ROWS.labelGap - channelSpace - ROWS.figureWidth);

        writeText(context, label, { x: area.x, y: centre }, { font: labelFont, color: MUTED, baseline: 'middle' });

        if (point.figure) {
            writeText(context, point.figure, { x: figureRight, y: centre }, { font: figureFont, color: INK, align: 'right', baseline: 'middle' });
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

/** Where the comparison line sits: under the change when there is one, else under the figure. */
const comparisonBaseline = (options: ShareCardOptions): number => {
    if (options.change) {
        return HEADLINE.comparisonUnderChange;
    }

    return HEADLINE.comparison;
};

/** The subject and what and when on the left, the figure with its change and comparison on the right. */
const paintHeadline = (context: CanvasRenderingContext2D, options: ShareCardOptions, panel: Box): void => {
    const left = panel.x + PANEL.padding;
    const right = panel.x + panel.width - PANEL.padding;
    const limit = panel.width - PANEL.padding * 2 - HEADLINE.reserve;

    paintIdentity(context, options.subject, `${options.label} · ${options.range}`, { x: left, y: panel.y + IDENTITY.top }, limit);
    writeText(context, options.figure, { x: right, y: panel.y + HEADLINE.figure }, { font: heading(500, 68), color: INK, align: 'right' });

    if (options.change) {
        paintChange(context, options.change, { x: right, y: panel.y + HEADLINE.change }, HEADLINE.changeSize, 'right');
    }

    if (options.comparison) {
        writeText(context, options.comparison, { x: right, y: panel.y + comparisonBaseline(options) }, { font: sans(500, 18), color: MUTED, align: 'right' });
    }
};

type Column = { middle: number; width: number };

/**
 * A column split by channel, bottom up in the page's order, with only its top
 * rounded like unovis' stacked bars. `colorOf` resolves each channel's color.
 */
const paintStack = (
    context: CanvasRenderingContext2D,
    segments: ShareSegment[],
    column: Column,
    y: (value: number) => number,
    colorOf: (color: string) => string,
): void => {
    const parts = segments.filter((segment) => segment.value > 0);
    let base = 0;

    parts.forEach((segment, index) => {
        const top = base + segment.value;

        context.fillStyle = colorOf(segment.color);

        if (index === parts.length - 1) {
            fillBar(context, y(base), y(top), column.middle, column.width, false);
        } else {
            context.fillRect(column.middle - column.width / 2, y(top), column.width, y(base) - y(top));
        }

        base = top;
    });
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

/** One column per bucket from zero: in the tint's strong color, or split by channel for `stacked`. */
const paintBars = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    plot: Box,
    x: (index: number) => number,
    y: (value: number) => number,
    zero: number,
): void => {
    const slot = plot.width / options.points.length;
    const width = Math.max(Math.min(slot * (1 - CHART.barPadding), CHART.barMaxWidth), 2);
    const colorOf = colorResolver();

    options.points.forEach((point, index) => {
        if (point.value === null || point.value === 0) {
            return;
        }

        if (point.segments) {
            paintStack(context, point.segments, { middle: x(index), width }, y, colorOf);

            return;
        }

        context.fillStyle = options.tint.strong;
        fillBar(context, zero, y(point.value), x(index), width, false);
    });
};

/** The known points joined by a monotone line, with a dot on each. */
const paintLine = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    x: (index: number) => number,
    y: (value: number) => number,
): void => {
    const known = options.points.flatMap((point, index) => (point.value === null ? [] : [{ x: x(index), y: y(point.value) }]));

    context.strokeStyle = options.tint.strong;
    context.lineWidth = CHART.lineWidth;
    context.lineJoin = 'round';
    context.lineCap = 'round';
    traceMonotone(context, known);
    context.stroke();

    context.fillStyle = options.tint.strong;
    known.forEach((point) => {
        context.beginPath();
        context.arc(point.x, point.y, CHART.dotRadius, 0, Math.PI * 2);
        context.fill();
    });
};

/** Which bucket the `mark`-th of `marks` date labels names, spread evenly over `count` buckets. */
const dateIndex = (mark: number, marks: number, count: number): number => {
    if (marks === 1) {
        return 0;
    }

    return Math.round(((count - 1) * mark) / (marks - 1));
};

/** Where a date label goes: the first and last flush with the plot's edges, the others under their bucket. */
const datePlacement = (
    mark: number,
    marks: number,
    plot: Box,
    bucketX: number,
): { x: number; align: CanvasTextAlign } => {
    if (mark === 0) {
        return { x: plot.x, align: 'left' };
    }

    if (mark === marks - 1) {
        return { x: plot.x + plot.width, align: 'right' };
    }

    return { x: bucketX, align: 'center' };
};

/** A few dates under the plot, so the period reads at a glance. */
const paintDates = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    plot: Box,
    x: (index: number) => number,
): void => {
    const marks = Math.min(PLOT.maxDates, options.points.length);

    for (let mark = 0; mark < marks; mark += 1) {
        const index = dateIndex(mark, marks, options.points.length);
        const placement = datePlacement(mark, marks, plot, x(index));

        writeText(context, options.points[index].label, { x: placement.x, y: plot.y + plot.height + PLOT.dates }, {
            font: CHART.tickFont,
            color: MUTED,
            align: placement.align,
        });
    }
};

/** The headline over a line, columns or one bar per channel. */
const paintChart = (context: CanvasRenderingContext2D, options: ShareCardOptions, images: ShareImages, panel: Box): void => {
    paintHeadline(context, options, panel);

    const left = panel.x + PANEL.padding;
    const right = panel.x + panel.width - PANEL.padding;
    const plot = {
        x: left + PLOT.gutter,
        y: panel.y + PLOT.top,
        width: right - left - PLOT.gutter,
        height: panel.height - PLOT.top - PLOT.bottom,
    };
    const values = options.points.flatMap((point) => (point.value === null ? [] : [point.value]));

    if (values.length === 0) {
        return;
    }

    if (options.shape === 'rows') {
        paintRows(context, options, images, { x: left, y: plot.y, width: right - left, height: plot.height + PLOT.dates });

        return;
    }

    const axis = valueAxis(values, options.shape);
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

    if (options.shape === 'bar' || options.shape === 'stacked') {
        paintBars(context, options, plot, x, y, plot.y + plot.height - axis.zero * plot.height);
    } else {
        paintLine(context, options, x, y);
    }

    paintDates(context, options, plot, x);
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

    return count <= 8 ? 4 : 5;
};

/** A tile's figure size before it shrinks to fit: smaller in a crowded grid, largest in a single row. */
const tileFigureSize = (columns: number, rows: number): number => {
    if (columns >= 4) {
        return 52;
    }

    return rows === 1 ? 84 : 64;
};

/** Every number of the Summary, split by ink rules like a cover's settings panel. */
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
    /** More rows shrink every tile instead of dropping numbers. */
    const fit = Math.min(1, tileHeight / SUMMARY.tileHeight);
    const figureSize = tileFigureSize(columns, rows) * fit;
    /** The last row stretches its tiles over the whole width, so no tile is left empty. */
    const rowSize = (row: number): number => (row < rows - 1 ? columns : tiles.length - columns * (rows - 1));

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
        const width = grid.width / rowSize(row);
        const inset = SUMMARY.inset * fit;
        const left = grid.x + width * (index % columns) + inset;
        const limit = width - inset * 2;
        const labelLine = grid.y + tileHeight * row + SUMMARY.label * fit;
        const labelFont = sans(500, SUMMARY.labelSize * fit);
        const figureFont = heading(500, figureSize);

        writeText(context, ellipsise(context, tile.label, labelFont, limit), { x: left, y: labelLine }, { font: labelFont, color: MUTED });
        writeText(context, ellipsise(context, tile.figure ?? '—', figureFont, limit), { x: left - 2 * fit, y: labelLine + figureSize + SUMMARY.figureGap * fit }, {
            font: figureFont,
            color: tile.figure === null ? MUTED : INK,
        });

        if (tile.change) {
            paintChange(context, tile.change, { x: left, y: labelLine + figureSize + SUMMARY.changeGap * fit }, SUMMARY.changeSize * fit, 'left');
        }
    });
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

    if (options.shape === 'tiles') {
        paintSummary(context, options, panel);
    } else {
        paintChart(context, options, images, panel);
    }

    paintMark(context, options.mark);
};

/** The card at download resolution, off screen, as a PNG named after its metric and today: `trypost-followers-2026-10-31.png`. */
export const shareCardFile = async (options: ShareCardOptions): Promise<File> => {
    const canvas = document.createElement('canvas');
    const name = `trypost-${options.key.replaceAll('_', '-')}-${date.userNow().format('YYYY-MM-DD')}.png`;

    drawShareCard(canvas, options, await prepareShareCard(options));

    return canvasToFile(canvas, name, 'image/png');
};
