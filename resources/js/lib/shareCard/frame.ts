import { ink, muted, surface } from '@/lib/shareCard/colors';
import { paintChange } from '@/lib/shareCard/draw';
import { ellipsise, writeText } from '@/lib/shareCard/text';
import {
    BORDER,
    heading,
    HEADLINE,
    IDENTITY,
    PANEL,
    PLOT,
    sans,
    SHARE_CARD_SIZE,
    type ShareTint,
} from '@/lib/shareCard/theme';
import type { Box, Point, ShareCardOptions, SharePoint } from '@/lib/shareCard/types';

/** The home hero's dot grid in the ground's corners, fading toward the middle. */
const DOTS = { gap: 24, radius: 1.4, opacity: 0.22, reach: 0.38 };

/** The covers' background: the flat tint with the dot grid in the top-right and bottom-left corners. */
const drawGround = (context: CanvasRenderingContext2D, tint: ShareTint): void => {
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

/** Each tint's ground, drawn once and kept until a canvas of another pixel density asks for it. */
const grounds = new Map<string, { pixels: number; canvas: HTMLCanvasElement }>();

/** The ground for `tint` at the canvas' pixel density, drawn the first time it is asked for at that density. */
const groundFor = (tint: ShareTint, pixels: number): HTMLCanvasElement | null => {
    const known = grounds.get(tint.key);

    if (known && known.pixels === pixels) {
        return known.canvas;
    }

    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');

    if (!context) {
        return null;
    }

    canvas.width = SHARE_CARD_SIZE.width * pixels;
    canvas.height = SHARE_CARD_SIZE.height * pixels;
    context.setTransform(pixels, 0, 0, pixels, 0, 0);
    drawGround(context, tint);
    grounds.set(tint.key, { pixels, canvas });

    return canvas;
};

/** Paints the tint and its dots under the panel, from the drawn ground when the browser can keep one. */
export const paintGround = (context: CanvasRenderingContext2D, tint: ShareTint): void => {
    const ground = groundFor(tint, context.getTransform().a);

    if (!ground) {
        drawGround(context, tint);

        return;
    }

    context.drawImage(ground, 0, 0, SHARE_CARD_SIZE.width, SHARE_CARD_SIZE.height);
};

/** A white surface with the ink border and no shadow. */
export const paintPanel = (context: CanvasRenderingContext2D): Box => {
    const { width, height } = SHARE_CARD_SIZE;
    const panel = {
        x: PANEL.x,
        y: PANEL.y,
        width: width - PANEL.x * 2,
        height: height - PANEL.y - PANEL.band,
    };

    context.beginPath();
    context.roundRect(panel.x, panel.y, panel.width, panel.height, PANEL.radius);
    context.fillStyle = surface();
    context.fill();
    context.strokeStyle = ink();
    context.lineWidth = BORDER;
    context.stroke();

    return panel;
};

/** The grid the logo's paths are drawn on. */
const LOGO_GRID = 410;

/** The TryPost mark, the four quarters of `AppLogo.vue`. */
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
    context.fillStyle = surface();
    context.fillRect(at.x, at.y, size, size);
    context.translate(at.x, at.y);
    context.scale(size / LOGO_GRID, size / LOGO_GRID);

    for (const [path, color] of quarters) {
        context.fillStyle = color;
        context.fill(new Path2D(path));
    }

    context.restore();
};

/** The signature's logo, the gap after it and its text size. */
const MARK = { logo: 32, gap: 12, size: 24 };

/** The signature, centred in the band under the panel: whoever sees the post sees where it came from. */
export const paintMark = (context: CanvasRenderingContext2D, mark: string): void => {
    const font = sans(600, MARK.size);
    const centre = SHARE_CARD_SIZE.height - PANEL.band / 2;

    context.font = font;

    const start = (SHARE_CARD_SIZE.width - (MARK.logo + MARK.gap + context.measureText(mark).width)) / 2;

    paintLogo(context, { x: start, y: centre - MARK.logo / 2 }, MARK.logo);
    writeText(context, mark, { x: start + MARK.logo + MARK.gap, y: centre + 1 }, { font, color: ink(), baseline: 'middle' });
};

/** The workspace or channel name, with what and when under it. */
export const paintIdentity = (
    context: CanvasRenderingContext2D,
    subject: string,
    detail: string,
    at: Point,
    limit: number,
): void => {
    const nameFont = heading(600, IDENTITY.nameSize);
    const detailFont = sans(500, IDENTITY.detailSize);

    writeText(context, ellipsise(context, subject, nameFont, limit), { x: at.x, y: at.y + IDENTITY.name }, { font: nameFont, color: ink() });
    writeText(context, ellipsise(context, detail, detailFont, limit), { x: at.x, y: at.y + IDENTITY.detail }, { font: detailFont, color: muted() });
};

/** The subject and what and when on the left, the figure with its change on the right. */
export const paintHeadline = (context: CanvasRenderingContext2D, options: ShareCardOptions, panel: Box): void => {
    const left = panel.x + PANEL.padding;
    const right = panel.x + panel.width - PANEL.padding;
    const limit = panel.width - PANEL.padding * 2 - HEADLINE.reserve;

    paintIdentity(context, options.subject, `${options.label} · ${options.range}`, { x: left, y: panel.y + IDENTITY.top }, limit);
    writeText(context, options.figure, { x: right, y: panel.y + HEADLINE.figure }, { font: heading(500, HEADLINE.figureSize), color: ink(), align: 'right' });

    if (options.change) {
        paintChange(context, options.change, { x: right, y: panel.y + HEADLINE.change }, HEADLINE.changeSize, 'right');
    }
};

/** The plot under the headline, past the gutter for the value ticks. */
export const headlinePlot = (panel: Box): Box => ({
    x: panel.x + PANEL.padding + PLOT.gutter,
    y: panel.y + PLOT.top,
    width: panel.width - PANEL.padding * 2 - PLOT.gutter,
    height: panel.height - PLOT.top - PLOT.bottom,
});

/** The values of the points that have one. */
export const pointValues = (points: SharePoint[]): number[] => points.flatMap((point) => (point.value === null ? [] : [point.value]));
