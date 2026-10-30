/**
 * The Insights share card: a chart or a headline number drawn on a canvas, to
 * download or post. Drawn rather than captured from the DOM, so it looks the
 * same in every browser and needs no dependency. It holds only vectors and
 * text: remote avatars are left out on purpose, because a network's CDN sends
 * no CORS header and one such image would stop the canvas exporting.
 */

export type ShareTheme = 'light' | 'dark';

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

export type ShareCardOptions = {
    variant: 'chart' | 'summary';
    theme: ShareTheme;
    color: string;
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

export const SHARE_COLORS = [
    '#7c3aed',
    '#2a78d6',
    '#1baf7a',
    '#eb6834',
    '#e87ba4',
] as const;

type Palette = {
    ground: string;
    ink: string;
    muted: string;
    rule: string;
    edge: string;
    up: string;
    down: string;
};

const PALETTES: Record<ShareTheme, Palette> = {
    light: {
        ground: '#ffffff',
        ink: '#292928',
        muted: '#6f6e6c',
        rule: '#eae8e5',
        edge: '#dedcd9',
        up: '#337046',
        down: '#9f1d0e',
    },
    dark: {
        ground: '#191919',
        ink: '#f7f6f3',
        muted: '#bcbab6',
        rule: '#2e2e2e',
        edge: '#3a3a3a',
        up: '#b0ec9c',
        down: '#f87171',
    },
};

const SANS = "Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif";

const DISPLAY = "Outfit, Inter, ui-sans-serif, system-ui, sans-serif";

const PAD = 64;

/** Canvas draws in the fallback face unless the page's fonts are loaded first. */
export const loadShareFonts = async (): Promise<void> => {
    if (!('fonts' in document)) {
        return;
    }

    await Promise.all(
        [`500 24px ${SANS}`, `600 24px ${SANS}`, `500 96px ${DISPLAY}`].map(
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

const tint = (hex: string, alpha: number): string => {
    const value = hex.replace('#', '');
    const r = Number.parseInt(value.slice(0, 2), 16);
    const g = Number.parseInt(value.slice(2, 4), 16);
    const b = Number.parseInt(value.slice(4, 6), 16);

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
};

/** The TryPost mark, the four quarters of `AppLogo.vue`, on a 410 grid. */
const paintLogo = (
    context: CanvasRenderingContext2D,
    x: number,
    y: number,
    size: number,
    theme: ShareTheme,
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
    context.fillStyle = theme === 'dark' ? '#040000' : '#ffffff';
    context.fillRect(x, y, size, size);
    context.translate(x, y);
    context.scale(size / 410, size / 410);

    for (const [path, color] of quarters) {
        context.fillStyle = color;
        context.fill(new Path2D(path));
    }

    context.restore();
};

/** The signature: whoever sees the post sees where it came from. */
const paintMark = (
    context: CanvasRenderingContext2D,
    palette: Palette,
    options: ShareCardOptions,
    right: number,
    baseline: number,
): void => {
    const logo = 26;
    const gap = 10;

    context.font = `500 20px ${SANS}`;
    context.textAlign = 'left';

    const width = context.measureText(options.mark).width;
    const start = right - width - logo - gap;

    paintLogo(context, start, baseline - logo + 6, logo, options.theme);
    context.fillStyle = palette.muted;
    context.fillText(options.mark, start + logo + gap, baseline);
};

const paintChange = (
    context: CanvasRenderingContext2D,
    palette: Palette,
    change: ShareChange,
    x: number,
    baseline: number,
    size: number,
): void => {
    context.font = `600 ${size}px ${SANS}`;
    context.textAlign = 'left';
    context.fillStyle =
        change.direction === 'up'
            ? palette.up
            : change.direction === 'down'
              ? palette.down
              : palette.muted;

    const arrow =
        change.direction === 'up' ? '↗' : change.direction === 'down' ? '↘' : '→';

    context.fillText(`${arrow} ${change.label}`, x, baseline);
};

/** Subject and period on the left, the headline number on the right. */
const paintHeader = (
    context: CanvasRenderingContext2D,
    palette: Palette,
    options: ShareCardOptions,
    width: number,
): void => {
    context.textAlign = 'left';
    context.fillStyle = palette.muted;
    context.font = `500 22px ${SANS}`;
    context.fillText(
        ellipsise(context, `${options.metric} · ${options.range}`, width - PAD * 2 - 380),
        PAD,
        100,
    );

    context.fillStyle = palette.ink;
    context.font = `600 34px ${DISPLAY}`;
    context.fillText(ellipsise(context, options.subject, width - PAD * 2 - 380), PAD, 146);

    context.textAlign = 'right';
    context.font = `500 64px ${DISPLAY}`;
    context.fillText(options.figure, width - PAD, 140);

    if (options.change) {
        context.font = `600 22px ${SANS}`;

        const label = `${options.change.direction === 'up' ? '↗' : options.change.direction === 'down' ? '↘' : '→'} ${options.change.label}`;
        const labelWidth = context.measureText(label).width;

        paintChange(context, palette, options.change, width - PAD - labelWidth, 178, 22);
    }

    context.textAlign = 'left';
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
    palette: Palette,
    options: ShareCardOptions,
    width: number,
    height: number,
): void => {
    paintHeader(context, palette, options, width);

    const plot = { x: PAD + 92, y: 228, width: width - PAD * 2 - 92, height: height - 228 - 132 };
    const values = options.points
        .map((point) => point.value)
        .filter((value): value is number => value !== null);

    if (values.length === 0) {
        paintMark(context, palette, options, width - PAD, height - 44);

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
    context.lineWidth = 1;

    for (let value = bottom; value <= top + step / 1000; value += step) {
        context.strokeStyle = palette.rule;
        context.beginPath();
        context.moveTo(plot.x, y(value));
        context.lineTo(plot.x + plot.width, y(value));
        context.stroke();
        context.fillStyle = palette.muted;
        context.fillText(options.tick(value), plot.x - 18, y(value));
    }

    context.setLineDash([]);
    context.textBaseline = 'alphabetic';

    if (options.shape === 'bar') {
        const barWidth = Math.max(Math.min(slot * 0.6, 28), 3);
        const zero = y(Math.max(0, bottom));

        options.points.forEach((point, index) => {
            if (point.value === null || point.value === 0) {
                return;
            }

            const end = y(point.value);

            roundedRect(context, x(index) - barWidth / 2, Math.min(end, zero), barWidth, Math.abs(zero - end), 5);
            context.fillStyle = options.color;
            context.fill();
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
        fill.addColorStop(0, tint(options.color, 0.28));
        fill.addColorStop(1, tint(options.color, 0));
        context.fillStyle = fill;
        context.fill();

        context.beginPath();
        known.forEach((point, index) =>
            index === 0
                ? context.moveTo(x(point.index), y(point.value))
                : context.lineTo(x(point.index), y(point.value)),
        );
        context.strokeStyle = options.color;
        context.lineWidth = 4;
        context.lineJoin = 'round';
        context.lineCap = 'round';
        context.stroke();

        const last = known[known.length - 1];

        context.beginPath();
        context.arc(x(last.index), y(last.value), 8, 0, Math.PI * 2);
        context.fillStyle = options.color;
        context.fill();
        context.strokeStyle = palette.ground;
        context.lineWidth = 4;
        context.stroke();
    }

    context.font = `500 18px ${SANS}`;
    context.fillStyle = palette.muted;

    const marks = Math.min(5, options.points.length);

    for (let mark = 0; mark < marks; mark += 1) {
        const index = marks === 1 ? 0 : Math.round(((options.points.length - 1) * mark) / (marks - 1));

        context.textAlign = mark === 0 ? 'left' : mark === marks - 1 ? 'right' : 'center';
        context.fillText(
            options.points[index].label,
            mark === 0 ? plot.x : mark === marks - 1 ? plot.x + plot.width : x(index),
            plot.y + plot.height + 38,
        );
    }

    context.textAlign = 'left';
    paintMark(context, palette, options, width - PAD, height - 44);
};

/** One headline number, large, with what it is and how it moved. */
const paintSummary = (
    context: CanvasRenderingContext2D,
    palette: Palette,
    options: ShareCardOptions,
    width: number,
    height: number,
): void => {
    context.textAlign = 'left';
    context.fillStyle = palette.ink;
    context.font = `600 34px ${DISPLAY}`;
    context.fillText(ellipsise(context, options.subject, width - PAD * 2), PAD, 116);

    context.fillStyle = palette.muted;
    context.font = `500 22px ${SANS}`;
    context.fillText(options.range, PAD, 156);

    context.fillStyle = options.color;
    context.font = `600 26px ${SANS}`;
    context.fillText(options.metric, PAD, 300);

    context.fillStyle = palette.ink;
    context.font = `500 168px ${DISPLAY}`;
    context.fillText(options.figure, PAD - 6, 460);

    if (options.change) {
        const figureWidth = context.measureText(options.figure).width;

        paintChange(context, palette, options.change, PAD + figureWidth + 28, 452, 40);
    }

    paintMark(context, palette, options, width - PAD, height - 44);
};

export const drawShareCard = (
    canvas: HTMLCanvasElement,
    options: ShareCardOptions,
    scale = SHARE_EXPORT_SCALE,
): void => {
    const { width, height } = SHARE_CARD_SIZE;
    const palette = PALETTES[options.theme];

    canvas.width = width * scale;
    canvas.height = height * scale;

    const context = canvas.getContext('2d');

    if (context === null) {
        return;
    }

    context.setTransform(scale, 0, 0, scale, 0, 0);
    context.fillStyle = palette.ground;
    context.fillRect(0, 0, width, height);
    context.strokeStyle = palette.edge;
    context.lineWidth = 2;
    roundedRect(context, 1, 1, width - 2, height - 2, 28);
    context.stroke();

    if (options.variant === 'summary') {
        paintSummary(context, palette, options, width, height);
    } else {
        paintChart(context, palette, options, width, height);
    }
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
