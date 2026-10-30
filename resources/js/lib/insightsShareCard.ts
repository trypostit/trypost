/**
 * The Insights share card: a chart or a headline number drawn on a canvas, to
 * download or post. Drawn rather than captured from the DOM, so it looks the
 * same in every browser and needs no dependency. It holds only vectors and
 * text: remote avatars are left out on purpose, because a network's CDN sends
 * no CORS header and one such image would stop the canvas exporting.
 */

export type ShareShape = 'line' | 'bar';

export type ShareChange = { label: string; direction: 'up' | 'down' | 'flat' };

export type SharePoint = { label: string; value: number | null };

/** One metric a share dialog can draw, with the post text it opens the composer with. */
export type ShareMetricOption = {
    key: string;
    label: string;
    figure: string;
    change: ShareChange | null;
    points: SharePoint[];
    shape: ShareShape;
    tick: (value: number) => string;
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
    points: SharePoint[];
    shape: ShareShape;
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
    { key: 'blue', ground: '#def0ff', strong: '#2563eb' },
    { key: 'green', ground: '#d9f1d1', strong: '#16a34a' },
    { key: 'peach', ground: '#ffdbd6', strong: '#ea580c' },
    { key: 'yellow', ground: '#fdefc3', strong: '#ca8a04' },
];

const INK = '#292928';
const MUTED = '#5a5a59';
const RULE = '#e2e0dc';
const BORDER = 2.5;
const CHANGE_COLORS = {
    up: { text: '#337046', fill: '#e3f5dc' },
    down: { text: '#9f1d0e', fill: '#ffe9e5' },
    flat: { text: MUTED, fill: '#f3f2f0' },
} as const;

const SANS = "Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif";

/** The white surface, inset from the tint like a cover's panel. */
const PANEL = { x: 56, y: 48, radius: 24, padding: 48 };
const PANEL_BOTTOM = 92;

/** Canvas draws in the fallback face unless the page's fonts are loaded first. */
export const loadShareFonts = async (): Promise<void> => {
    if (!('fonts' in document)) {
        return;
    }

    await Promise.all(
        [`500 24px ${SANS}`, `600 24px ${SANS}`, `700 96px ${SANS}`].map(
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

const alpha = (hex: string, opacity: number): string => {
    const value = hex.replace('#', '');
    const r = Number.parseInt(value.slice(0, 2), 16);
    const g = Number.parseInt(value.slice(2, 4), 16);
    const b = Number.parseInt(value.slice(4, 6), 16);

    return `rgba(${r}, ${g}, ${b}, ${opacity})`;
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
    context.beginPath();
    context.arc(x + size / 2, y + size / 2, size / 2, 0, Math.PI * 2);
    context.strokeStyle = INK;
    context.lineWidth = 2;
    context.stroke();
};

/** The signature on the tint, under the panel: whoever sees the post sees where it came from. */
const paintMark = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    width: number,
    height: number,
): void => {
    const logo = 32;
    const gap = 12;
    const baseline = height - PANEL_BOTTOM / 2 + 9;

    context.font = `600 24px ${SANS}`;
    context.textAlign = 'left';

    const textWidth = context.measureText(options.mark).width;
    const start = width - PANEL.x - textWidth - logo - gap;

    paintLogo(context, start, baseline - logo + 8, logo);
    context.fillStyle = INK;
    context.fillText(options.mark, start + logo + gap, baseline);
};

/** The change as the page's MetricChange: a soft pill with the arrow and the label. */
const paintChange = (
    context: CanvasRenderingContext2D,
    change: ShareChange,
    anchor: number,
    top: number,
    size: number,
    align: 'left' | 'right',
): void => {
    const colors = CHANGE_COLORS[change.direction];
    const arrow = change.direction === 'up' ? '↗' : change.direction === 'down' ? '↘' : '→';
    const label = `${arrow} ${change.label}`;

    context.font = `600 ${size}px ${SANS}`;

    const padX = size * 0.5;
    const pillWidth = context.measureText(label).width + padX * 2;
    const pillHeight = size * 1.6;
    const x = align === 'right' ? anchor - pillWidth : anchor;

    roundedRect(context, x, top, pillWidth, pillHeight, pillHeight / 2);
    context.fillStyle = colors.fill;
    context.fill();
    context.fillStyle = colors.text;
    context.textAlign = 'left';
    context.textBaseline = 'middle';
    context.fillText(label, x + padX, top + pillHeight / 2 + 1);
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
        return [Math.min(0, low), high === Math.min(0, low) ? 1 : high];
    }

    const padding = Math.max((high - low) * 0.15, 1);

    return [low - padding, high + padding];
};

const paintChart = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    panel: { x: number; y: number; width: number; height: number },
): void => {
    const left = panel.x + PANEL.padding;
    const right = panel.x + panel.width - PANEL.padding;
    const textLimit = panel.width - PANEL.padding * 2 - 360;

    context.textAlign = 'left';
    context.fillStyle = MUTED;
    context.font = `500 22px ${SANS}`;
    context.fillText(ellipsise(context, `${options.metric} · ${options.range}`, textLimit), left, panel.y + 70);

    context.fillStyle = INK;
    context.font = `700 34px ${SANS}`;
    context.fillText(ellipsise(context, options.subject, textLimit), left, panel.y + 114);

    context.textAlign = 'right';
    context.font = `700 64px ${SANS}`;
    context.fillText(options.figure, right, panel.y + 106);

    if (options.change) {
        paintChange(context, options.change, right, panel.y + 124, 20, 'right');
    }

    const plot = {
        x: left + 72,
        y: panel.y + 196,
        width: right - left - 72,
        height: panel.height - 196 - 76,
    };
    const values = options.points
        .map((point) => point.value)
        .filter((value): value is number => value !== null);

    if (values.length === 0) {
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

    context.font = `500 18px ${SANS}`;
    context.textAlign = 'right';
    context.textBaseline = 'middle';
    context.setLineDash([4, 6]);
    context.lineWidth = 1.5;

    for (let value = bottom; value <= top + step / 1000; value += step) {
        context.strokeStyle = RULE;
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
        const barWidth = Math.max(Math.min(slot * 0.6, 30), 3);
        const zero = y(Math.max(0, bottom));
        const outlined = barWidth >= 10;

        options.points.forEach((point, index) => {
            if (point.value === null || point.value === 0) {
                return;
            }

            const end = y(point.value);

            roundedRect(context, x(index) - barWidth / 2, Math.min(end, zero), barWidth, Math.abs(zero - end), 6);
            context.fillStyle = color;
            context.fill();

            if (outlined) {
                context.strokeStyle = INK;
                context.lineWidth = 2;
                context.stroke();
            }
        });
    } else {
        const known = options.points
            .map((point, index) => ({ index, value: point.value }))
            .filter((point): point is { index: number; value: number } => point.value !== null);

        context.beginPath();
        context.moveTo(x(known[0].index), plot.y + plot.height);
        known.forEach((point) => context.lineTo(x(point.index), y(point.value)));
        context.lineTo(x(known[known.length - 1].index), plot.y + plot.height);
        context.closePath();

        const fill = context.createLinearGradient(0, plot.y, 0, plot.y + plot.height);
        fill.addColorStop(0, alpha(color, 0.24));
        fill.addColorStop(1, alpha(color, 0));
        context.fillStyle = fill;
        context.fill();

        context.beginPath();
        known.forEach((point, index) =>
            index === 0
                ? context.moveTo(x(point.index), y(point.value))
                : context.lineTo(x(point.index), y(point.value)),
        );
        context.strokeStyle = color;
        context.lineWidth = 4;
        context.lineJoin = 'round';
        context.lineCap = 'round';
        context.stroke();

        const last = known[known.length - 1];

        context.beginPath();
        context.arc(x(last.index), y(last.value), 9, 0, Math.PI * 2);
        context.fillStyle = color;
        context.fill();
        context.strokeStyle = INK;
        context.lineWidth = BORDER;
        context.stroke();
    }

    context.font = `500 18px ${SANS}`;
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

/** One headline number, large, with what it is and how it moved. */
const paintSummary = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    panel: { x: number; y: number; width: number; height: number },
): void => {
    const left = panel.x + PANEL.padding;
    const limit = panel.width - PANEL.padding * 2;

    context.textAlign = 'left';
    context.fillStyle = INK;
    context.font = `700 34px ${SANS}`;
    context.fillText(ellipsise(context, options.subject, limit), left, panel.y + 84);

    context.fillStyle = MUTED;
    context.font = `500 22px ${SANS}`;
    context.fillText(options.range, left, panel.y + 124);

    context.fillStyle = options.tint.strong;
    context.font = `700 28px ${SANS}`;
    context.fillText(options.metric, left, panel.y + 262);

    context.fillStyle = INK;
    context.font = `700 168px ${SANS}`;
    context.fillText(options.figure, left - 6, panel.y + 424);

    if (options.change) {
        const figureWidth = context.measureText(options.figure).width;

        paintChange(context, options.change, left + figureWidth + 28, panel.y + 340, 36, 'left');
    }
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
