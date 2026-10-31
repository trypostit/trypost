/**
 * The Insights share card: a chart or a headline number drawn on a canvas, to
 * download or post. Drawn rather than captured from the DOM, so it looks the
 * same in every browser and needs no dependency. It holds only vectors and
 * text: remote avatars are left out on purpose, because a network's CDN sends
 * no CORS header and one such image would stop the canvas exporting.
 */

/** A line or columns over time, or one horizontal bar per channel. */
export type ShareShape = 'line' | 'bar' | 'rows';

export type ShareChange = { label: string; direction: 'up' | 'down' | 'flat' };

/** A bucket over time, or a channel for `rows`, which may bring its own color. */
export type SharePoint = { label: string; value: number | null; color?: string };

/** One number of the Summary, as its card prints it. */
export type ShareTile = { label: string; figure: string; change: ShareChange | null };

/** One metric a share dialog can draw, with the post text it opens the composer with. */
export type ShareMetricOption = {
    key: string;
    label: string;
    figure: string;
    change: ShareChange | null;
    /** What the change is measured against: "vs 120 in the previous period". */
    comparison: string | null;
    points: SharePoint[];
    shape: ShareShape;
    tick: (value: number) => string;
    /** Every number of the Summary, for the summary card. */
    tiles: ShareTile[];
    caption: string;
};

/** A background tint of the app with the strong color the data is drawn in. */
export type ShareTint = { key: string; ground: string; strong: string };

export type ShareCardOptions = {
    variant: 'chart' | 'summary';
    tint: ShareTint;
    /** The workspace or channel the numbers belong to. */
    subject: string;
    /** What the number is: "Followers". */
    metric: string;
    /** The period, as the page prints it. */
    range: string;
    figure: string;
    change: ShareChange | null;
    comparison: string | null;
    points: SharePoint[];
    shape: ShareShape;
    tiles: ShareTile[];
    /** Formats an axis value the way the page does. */
    tick: (value: number) => string;
    /** The signature: "Made with TryPost", translated. */
    mark: string;
};

export const SHARE_CARD_SIZE = { width: 1200, height: 675 };

export const SHARE_EXPORT_SCALE = 2;

/** The light tints of the "What's New" covers, so a shared card reads as TryPost. */
export const SHARE_TINTS: ShareTint[] = [
    { key: 'violet', ground: '#d2c7fd', strong: '#6d28d9' },
    { key: 'indigo', ground: '#dfe3fd', strong: '#4338ca' },
    { key: 'blue', ground: '#def0ff', strong: '#2563eb' },
    { key: 'cyan', ground: '#cff4fc', strong: '#0e7490' },
    { key: 'teal', ground: '#ccf5ea', strong: '#0f766e' },
    { key: 'green', ground: '#d9f1d1', strong: '#16a34a' },
    { key: 'lime', ground: '#eaf7c8', strong: '#4d7c0f' },
    { key: 'yellow', ground: '#fdefc3', strong: '#a16207' },
    { key: 'peach', ground: '#ffdbd6', strong: '#ea580c' },
    { key: 'red', ground: '#fde0e0', strong: '#dc2626' },
    { key: 'pink', ground: '#fce1ef', strong: '#db2777' },
    { key: 'neutral', ground: '#f4f1ec', strong: '#292928' },
];

const INK = '#292928';
const MUTED = '#5a5a59';
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
    tickSize: 22,
    barMaxWidth: 32,
    barPadding: 0.35,
    barRadius: 8,
    lineWidth: 4,
    dotRadius: 4,
};
const BORDER = 2.5;
/** `MetricChange`: `--success-text`, `--destructive-text` or muted, with Tabler's trending icons. */
const CHANGE_COLORS = { up: '#337046', down: '#7f0f00', flat: MUTED } as const;
const CHANGE_ICONS = {
    up: ['M3 17l6 -6l4 4l8 -8', 'M14 7l7 0l0 7'],
    down: ['M3 7l6 6l4 -4l8 8', 'M21 10l0 7l-7 0'],
    flat: ['M5 12l14 0'],
} as const;

const SANS = "Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif";

/** The app's heading face (`--font-heading`), for names and numbers. */
const HEADING = "Outfit, Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif";

/** The white surface, inset from the tint like a cover's panel. */
const PANEL = { x: 56, y: 48, radius: 24, padding: 48 };
const PANEL_BOTTOM = 92;

/** Canvas draws in the fallback face unless the page's fonts are loaded first. */
export const loadShareFonts = async (): Promise<void> => {
    if (!('fonts' in document)) {
        return;
    }

    await Promise.all(
        [`500 24px ${SANS}`, `600 24px ${SANS}`, `500 64px ${HEADING}`, `600 36px ${HEADING}`].map(
            (face) => document.fonts.load(face).catch(() => []),
        ),
    );
};

const ellipsise = (
    context: CanvasRenderingContext2D,
    text: string,
    limit: number,
): string => {
    if (context.measureText(text).width <= limit) {
        return text;
    }

    let kept = text;

    while (kept.length > 1 && context.measureText(`${kept}…`).width > limit) {
        kept = kept.slice(0, -1);
    }

    return `${kept}…`;
};

const roundedRect = (
    context: CanvasRenderingContext2D,
    x: number,
    y: number,
    width: number,
    height: number,
    radius: number,
): void => {
    const r = Math.min(radius, width / 2, Math.abs(height) / 2);

    context.beginPath();
    context.moveTo(x + r, y);
    context.arcTo(x + width, y, x + width, y + height, r);
    context.arcTo(x + width, y + height, x, y + height, r);
    context.arcTo(x, y + height, x, y, r);
    context.arcTo(x, y, x + width, y, r);
    context.closePath();
};

/**
 * The covers' background: the flat tint with the home hero's dot grid in the
 * top-right and bottom-left corners only, fading toward the middle.
 */
const paintGround = (
    context: CanvasRenderingContext2D,
    tint: ShareTint,
    width: number,
    height: number,
): void => {
    context.fillStyle = tint.ground;
    context.fillRect(0, 0, width, height);

    const reach = Math.hypot(width, height) * 0.38;

    for (let y = 12; y < height; y += 24) {
        for (let x = 12; x < width; x += 24) {
            const corner = Math.min(
                Math.hypot(width - x, y),
                Math.hypot(x, height - y),
            );
            const strength = 1 - corner / reach;

            if (strength <= 0) {
                continue;
            }

            context.fillStyle = `rgba(10, 10, 10, ${0.22 * strength})`;
            context.beginPath();
            context.arc(x, y, 1.4, 0, Math.PI * 2);
            context.fill();
        }
    }
};

/** A white surface with the ink border and no shadow. */
const paintPanel = (
    context: CanvasRenderingContext2D,
    width: number,
    height: number,
): { x: number; y: number; width: number; height: number } => {
    const panel = {
        x: PANEL.x,
        y: PANEL.y,
        width: width - PANEL.x * 2,
        height: height - PANEL.y - PANEL_BOTTOM,
    };

    roundedRect(context, panel.x, panel.y, panel.width, panel.height, PANEL.radius);
    context.fillStyle = '#ffffff';
    context.fill();
    context.strokeStyle = INK;
    context.lineWidth = BORDER;
    context.stroke();

    return panel;
};

/** The TryPost mark, the four quarters of `AppLogo.vue`, on a 410 grid. */
const paintLogo = (
    context: CanvasRenderingContext2D,
    x: number,
    y: number,
    size: number,
): void => {
    const quarters: [string, string][] = [
        ['M410 109.736V410H128.863L410 109.736Z', '#3660E0'],
        ['M410 0H281.138L205 81.3178L410 300.264V0Z', '#855ED4'],
        ['M0 0V300.264L205 81.3178L281.138 0H0Z', '#FFCC33'],
        ['M0 109.736V410H128.862L205 328.682L0 109.736Z', '#F39200'],
    ];

    context.save();
    context.beginPath();
    context.arc(x + size / 2, y + size / 2, size / 2, 0, Math.PI * 2);
    context.clip();
    context.fillStyle = '#ffffff';
    context.fillRect(x, y, size, size);
    context.translate(x, y);
    context.scale(size / 410, size / 410);

    for (const [path, color] of quarters) {
        context.fillStyle = color;
        context.fill(new Path2D(path));
    }

    context.restore();
};

/** The signature, centered in the band under the panel: whoever sees the post sees where it came from. */
const paintMark = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    width: number,
    height: number,
): void => {
    const logo = 32;
    const gap = 12;
    const centre = height - PANEL_BOTTOM / 2;

    context.font = `600 24px ${SANS}`;
    context.textAlign = 'left';
    context.textBaseline = 'middle';

    const start = (width - (logo + gap + context.measureText(options.mark).width)) / 2;

    paintLogo(context, start, centre - logo / 2, logo);
    context.fillStyle = INK;
    context.fillText(options.mark, start + logo + gap, centre + 1);
    context.textBaseline = 'alphabetic';
};

/** The workspace or channel name, what and when under it. */
const paintIdentity = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    left: number,
    top: number,
    limit: number,
    detail: string,
): void => {
    context.textAlign = 'left';
    context.fillStyle = INK;
    context.font = `600 36px ${HEADING}`;
    context.fillText(ellipsise(context, options.subject, limit), left, top + 34);

    context.fillStyle = MUTED;
    context.font = `500 22px ${SANS}`;
    context.fillText(ellipsise(context, detail, limit), left, top + 76);
};

/** The change as the page's `MetricChange`: the trending icon and the label, in the tone's color. */
const paintChange = (
    context: CanvasRenderingContext2D,
    change: ShareChange,
    anchor: number,
    top: number,
    size: number,
    align: 'left' | 'right',
): void => {
    const color = CHANGE_COLORS[change.direction];
    const icon = size * 1.15;
    const gap = size * 0.3;

    context.font = `600 ${size}px ${SANS}`;

    const width = icon + gap + context.measureText(change.label).width;
    const x = align === 'right' ? anchor - width : anchor;
    const middle = top + icon / 2;

    context.save();
    context.translate(x, middle - icon / 2);
    context.scale(icon / 24, icon / 24);
    context.strokeStyle = color;
    context.lineWidth = 2.5;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    CHANGE_ICONS[change.direction].forEach((path) => context.stroke(new Path2D(path)));
    context.restore();

    context.fillStyle = color;
    context.textAlign = 'left';
    context.textBaseline = 'middle';
    context.fillText(change.label, x + icon + gap, middle + 1);
    context.textBaseline = 'alphabetic';
};

const niceStep = (span: number, count: number): number => {
    const raw = span / count;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const normalised = raw / magnitude;

    return (
        (normalised <= 1
            ? 1
            : normalised <= 2
              ? 2
              : normalised <= 2.5
                ? 2.5
                : normalised <= 5
                  ? 5
                  : 10) * magnitude
    );
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

    if (shape === 'bar' || (low >= 0 && low <= high - low)) {
        const bottom = Math.min(0, low);
        const top = Math.max(0, high);

        return [bottom, top === bottom ? 1 : top];
    }

    const padding = Math.max((high - low) * 0.15, 1);

    return [low - padding, high + padding];
};

/** A bar from the baseline to its value, rounded only at the data end, like unovis' `roundedCorners`. */
const paintBar = (
    context: CanvasRenderingContext2D,
    x: number,
    zero: number,
    width: number,
    end: number,
): void => {
    const height = Math.abs(zero - end);
    const radius = Math.min(CHART.barRadius, width / 2, height);
    const up = end < zero;

    context.beginPath();

    if (up) {
        context.moveTo(x, zero);
        context.lineTo(x, end + radius);
        context.arcTo(x, end, x + radius, end, radius);
        context.arcTo(x + width, end, x + width, end + radius, radius);
        context.lineTo(x + width, zero);
    } else {
        context.moveTo(x, zero);
        context.lineTo(x, end - radius);
        context.arcTo(x, end, x + radius, end, radius);
        context.arcTo(x + width, end, x + width, end - radius, radius);
        context.lineTo(x + width, zero);
    }

    context.closePath();
    context.fill();
};

/** The line through the points as a monotone cubic, d3's `curveMonotoneX` the page's charts use. */
const traceMonotone = (
    context: CanvasRenderingContext2D,
    points: { x: number; y: number }[],
): void => {
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

const MAX_ROWS = 12;

/** A channel color the page gives as a CSS token (`var(--series-3)`), read as the color it stands for. */
const resolveColor = (color: string | undefined, fallback: string): string => {
    const token = color?.match(/^var\((--[\w-]+)\)$/)?.[1];

    if (!token) {
        return color ?? fallback;
    }

    return getComputedStyle(document.documentElement).getPropertyValue(token).trim() || fallback;
};

/**
 * One horizontal bar per channel from zero, losses to the left, in each
 * channel's color, like the page's Bar and Growth views. The channels with
 * the largest values keep their place when they do not all fit.
 */
const paintRows = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    area: { x: number; y: number; width: number; height: number },
): void => {
    const ranked = options.points
        .map((point, index) => ({ ...point, index }))
        .filter((point): point is SharePoint & { index: number; value: number } => point.value !== null)
        .sort((a, b) => Math.abs(b.value) - Math.abs(a.value))
        .slice(0, MAX_ROWS)
        .sort((a, b) => a.index - b.index);
    const values = ranked.map((point) => point.value);
    const [low, high] = domain(values, 'bar');
    const step = Math.max(1, niceStep(high - low || 1, 4));
    const top = Math.ceil(high / step) * step;
    const bottom = Math.floor(low / step) * step;
    const span = top - bottom || 1;
    const labelWidth = 220;
    const plot = { x: area.x + labelWidth, width: area.width - labelWidth, y: area.y, height: area.height - 44 };
    const x = (value: number): number => plot.x + ((value - bottom) / span) * plot.width;
    const rowHeight = plot.height / Math.max(ranked.length, 1);
    const thickness = Math.min(rowHeight * 0.55, 28);
    const labelSize = Math.min(22, Math.max(14, rowHeight * 0.55));

    context.setLineDash(CHART.gridDash);
    context.lineWidth = CHART.gridWidth;
    context.font = `500 ${CHART.tickSize}px ${SANS}`;
    context.textAlign = 'center';
    context.fillStyle = MUTED;

    for (let value = bottom; value <= top + step / 1000; value += step) {
        context.strokeStyle = CHART.grid;
        context.beginPath();
        context.moveTo(x(value), plot.y);
        context.lineTo(x(value), plot.y + plot.height);
        context.stroke();
        context.fillText(options.tick(value), x(value), plot.y + plot.height + 36);
    }

    context.setLineDash([]);

    const zero = x(Math.max(0, bottom));

    ranked.forEach((point, row) => {
        const centre = plot.y + rowHeight * (row + 0.5);

        context.textAlign = 'left';
        context.textBaseline = 'middle';
        context.fillStyle = MUTED;
        context.font = `500 ${labelSize}px ${SANS}`;
        context.fillText(ellipsise(context, point.label, labelWidth - 24), area.x, centre);
        context.textBaseline = 'alphabetic';

        if (point.value === 0) {
            return;
        }

        const end = x(point.value);
        const length = Math.abs(end - zero);
        const radius = Math.min(CHART.barRadius, thickness / 2, length);
        const startX = Math.min(end, zero);
        const topY = centre - thickness / 2;

        context.fillStyle = resolveColor(point.color, options.tint.strong);
        context.beginPath();

        if (end > zero) {
            context.moveTo(startX, topY);
            context.lineTo(end - radius, topY);
            context.arcTo(end, topY, end, topY + radius, radius);
            context.arcTo(end, topY + thickness, end - radius, topY + thickness, radius);
            context.lineTo(startX, topY + thickness);
        } else {
            context.moveTo(zero, topY);
            context.lineTo(end + radius, topY);
            context.arcTo(end, topY, end, topY + radius, radius);
            context.arcTo(end, topY + thickness, end + radius, topY + thickness, radius);
            context.lineTo(zero, topY + thickness);
        }

        context.closePath();
        context.fill();
    });
};

const paintChart = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    panel: { x: number; y: number; width: number; height: number },
): void => {
    const left = panel.x + PANEL.padding;
    const right = panel.x + panel.width - PANEL.padding;
    const textLimit = panel.width - PANEL.padding * 2 - 380;

    paintIdentity(context, options, left, panel.y + 40, textLimit, `${options.metric} · ${options.range}`);

    context.textAlign = 'right';
    context.fillStyle = INK;
    context.font = `500 68px ${HEADING}`;
    context.fillText(options.figure, right, panel.y + 100);

    if (options.change) {
        paintChange(context, options.change, right, panel.y + 116, 26, 'right');
    }

    if (options.comparison) {
        context.textAlign = 'right';
        context.fillStyle = MUTED;
        context.font = `500 18px ${SANS}`;
        context.fillText(options.comparison, right, panel.y + (options.change ? 176 : 136));
    }

    const plot = {
        x: left + 72,
        y: panel.y + 212,
        width: right - left - 72,
        height: panel.height - 212 - 76,
    };
    const values = options.points
        .map((point) => point.value)
        .filter((value): value is number => value !== null);

    if (values.length === 0) {
        return;
    }

    if (options.shape === 'rows') {
        paintRows(context, options, { x: left, y: plot.y, width: right - left, height: plot.height + 40 });

        return;
    }

    const [low, high] = domain(values, options.shape);
    const step = Math.max(1, niceStep(high - low || 1, 4));
    const top = Math.ceil(high / step) * step;
    const bottom = Math.floor(low / step) * step;
    const span = top - bottom || 1;
    const y = (value: number): number => plot.y + plot.height - ((value - bottom) / span) * plot.height;
    const slot = plot.width / options.points.length;
    const x = (index: number): number => plot.x + slot * (index + 0.5);

    context.font = `500 ${CHART.tickSize}px ${SANS}`;
    context.textAlign = 'right';
    context.textBaseline = 'middle';
    context.setLineDash(CHART.gridDash);
    context.lineWidth = CHART.gridWidth;

    for (let value = bottom; value <= top + step / 1000; value += step) {
        context.strokeStyle = CHART.grid;
        context.beginPath();
        context.moveTo(plot.x, y(value));
        context.lineTo(plot.x + plot.width, y(value));
        context.stroke();
        context.fillStyle = MUTED;
        context.fillText(options.tick(value), plot.x - 18, y(value));
    }

    context.setLineDash([]);
    context.textBaseline = 'alphabetic';

    const color = options.tint.strong;

    if (options.shape === 'bar') {
        const barWidth = Math.max(Math.min(slot * (1 - CHART.barPadding), CHART.barMaxWidth), 2);
        const zero = y(Math.max(0, bottom));

        context.fillStyle = color;

        options.points.forEach((point, index) => {
            if (point.value === null || point.value === 0) {
                return;
            }

            paintBar(context, x(index) - barWidth / 2, zero, barWidth, y(point.value));
        });
    } else {
        const known = options.points
            .map((point, index) => ({ x: x(index), y: point.value === null ? null : y(point.value) }))
            .filter((point): point is { x: number; y: number } => point.y !== null);

        context.strokeStyle = color;
        context.lineWidth = CHART.lineWidth;
        context.lineJoin = 'round';
        context.lineCap = 'round';
        traceMonotone(context, known);
        context.stroke();

        context.fillStyle = color;
        known.forEach((point) => {
            context.beginPath();
            context.arc(point.x, point.y, CHART.dotRadius, 0, Math.PI * 2);
            context.fill();
        });
    }

    context.font = `500 ${CHART.tickSize}px ${SANS}`;
    context.fillStyle = MUTED;

    const marks = Math.min(5, options.points.length);

    for (let mark = 0; mark < marks; mark += 1) {
        const index = marks === 1 ? 0 : Math.round(((options.points.length - 1) * mark) / (marks - 1));

        context.textAlign = mark === 0 ? 'left' : mark === marks - 1 ? 'right' : 'center';
        context.fillText(
            options.points[index].label,
            mark === 0 ? plot.x : mark === marks - 1 ? plot.x + plot.width : x(index),
            plot.y + plot.height + 40,
        );
    }

    context.textAlign = 'left';
};

const MAX_TILES = 8;

/** How many columns the Summary's numbers take, like the page's grid. */
const tileColumns = (count: number): number => {
    if (count <= 3) {
        return Math.max(count, 1);
    }

    return count === 4 ? 2 : count <= 6 ? 3 : 4;
};

/** Every number of the Summary, split by ink rules like a cover's settings panel. */
const paintSummary = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    panel: { x: number; y: number; width: number; height: number },
): void => {
    const left = panel.x + PANEL.padding;

    paintIdentity(context, options, left, panel.y + 40, panel.width - PANEL.padding * 2, options.range);

    const tiles = options.tiles.slice(0, MAX_TILES);
    const columns = tileColumns(tiles.length);
    const rows = Math.ceil(tiles.length / columns);
    const grid = { x: panel.x, y: panel.y + 168, width: panel.width, height: panel.height - 168 };
    const tileHeight = grid.height / Math.max(rows, 1);
    const figureSize = columns >= 4 ? 52 : rows === 1 ? 84 : 64;
    /** The last row stretches its tiles over the whole width, so no tile is left empty. */
    const rowSize = (row: number): number =>
        row < rows - 1 ? columns : tiles.length - columns * (rows - 1);

    context.strokeStyle = INK;
    context.lineWidth = BORDER;
    context.beginPath();

    for (let row = 0; row < rows; row += 1) {
        const top = grid.y + tileHeight * row;
        const width = grid.width / rowSize(row);

        context.moveTo(grid.x, top);
        context.lineTo(grid.x + grid.width, top);

        for (let column = 1; column < rowSize(row); column += 1) {
            context.moveTo(grid.x + width * column, top);
            context.lineTo(grid.x + width * column, top + tileHeight);
        }
    }

    context.stroke();

    tiles.forEach((tile, index) => {
        const row = Math.floor(index / columns);
        const width = grid.width / rowSize(row);
        const x = grid.x + width * (index % columns) + 36;
        const y = grid.y + tileHeight * row;
        const limit = width - 72;

        context.textAlign = 'left';
        context.fillStyle = MUTED;
        context.font = `500 24px ${SANS}`;
        context.fillText(ellipsise(context, tile.label, limit), x, y + 50);

        context.fillStyle = tile.figure === '—' ? MUTED : INK;
        context.font = `500 ${figureSize}px ${HEADING}`;
        context.fillText(ellipsise(context, tile.figure, limit), x - 2, y + 50 + figureSize + 6);

        if (tile.change) {
            paintChange(context, tile.change, x, y + 50 + figureSize + 22, 24, 'left');
        }
    });
};

export const drawShareCard = (
    canvas: HTMLCanvasElement,
    options: ShareCardOptions,
    scale = SHARE_EXPORT_SCALE,
): void => {
    const { width, height } = SHARE_CARD_SIZE;

    canvas.width = width * scale;
    canvas.height = height * scale;

    const context = canvas.getContext('2d');

    if (context === null) {
        return;
    }

    context.setTransform(scale, 0, 0, scale, 0, 0);
    paintGround(context, options.tint, width, height);

    const panel = paintPanel(context, width, height);

    if (options.variant === 'summary') {
        paintSummary(context, options, panel);
    } else {
        paintChart(context, options, panel);
    }

    paintMark(context, options, width, height);
};

/** The card at download resolution, off screen, as a PNG file. */
export const shareCardFile = (
    options: ShareCardOptions,
    filename: string,
): Promise<File> =>
    new Promise((resolve, reject) => {
        const canvas = document.createElement('canvas');

        drawShareCard(canvas, options);
        canvas.toBlob((blob) => {
            if (blob === null) {
                reject(new Error('The share card could not be exported.'));

                return;
            }

            resolve(new File([blob], filename, { type: 'image/png' }));
        }, 'image/png');
    });
