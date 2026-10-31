/**
 * The Insights share card: a chart or a headline number drawn on a canvas, to
 * download or post. Drawn rather than captured from the DOM, so it looks the
 * same in every browser and needs no dependency. Every image it draws loads
 * with CORS: one that does not would stop the canvas exporting.
 *
 * Each shape has its painter (`lines`, `rows`, `stacked`, `combo`, `summary`)
 * on the shared frame, text, drawing and channel helpers; `scale` and `curve`
 * hold the pure arithmetic the painters share with the page's charts.
 */

import date from '@/date';
import { canvasToFile } from '@/lib/canvasFile';

import { lightColor } from './colors';
import { paintCombo } from './combo';
import { paintGround, paintMark, paintPanel } from './frame';
import { prepareShareCard } from './images';
import { paintLinesCard } from './lines';
import { paintRowsCard } from './rows';
import { paintStackedCard } from './stacked';
import { paintSummary } from './summary';
import { SHARE_CARD_SIZE, SHARE_EXPORT_SCALE } from './theme';
import type { Box, ShareCardOptions, ShareImages, ShareShape } from './types';

export { periodFigure } from './combo';
export { prepareShareCard } from './images';
export { SHARE_CARD_SIZE, SHARE_TINTS, type ShareTint } from './theme';
export type {
    ShareCardOptions,
    ShareChannel,
    ShareLine,
    ShareMetricOption,
    SharePoint,
    ShareSeries,
    ShareShape,
    ShareTile,
} from './types';

/** What draws each shape inside the panel. */
const PAINTERS: Record<ShareShape, (context: CanvasRenderingContext2D, options: ShareCardOptions, images: ShareImages, panel: Box) => void> = {
    lines: paintLinesCard,
    rows: paintRowsCard,
    stacked: (context, options, _images, panel) => paintStackedCard(context, options, panel),
    combo: (context, options, _images, panel) => paintCombo(context, options, panel),
    tiles: (context, options, _images, panel) => paintSummary(context, options, panel),
};

/**
 * The card with every color as the browser computes it: a canvas cannot read
 * CSS variables, so the theme's colors are resolved once before painting.
 */
const withResolvedColors = (options: ShareCardOptions): ShareCardOptions => {

    return {
        ...options,
        points: options.points.map((point) => ({
            ...point,
            color: point.color && lightColor(point.color),
            segments: point.segments?.map((segment) => ({ ...segment, color: lightColor(segment.color) })),
        })),
        lines: options.lines.map((line) => ({ ...line, color: lightColor(line.color) })),
        series: options.series.map((series) => ({ ...series, color: lightColor(series.color) })),
    };
};

/**
 * The direction the reader's language writes in. The card keeps its layout
 * left to right in every language (every text is placed by a side, never by
 * `start`), and each text runs in this direction, so an Arabic line with a
 * Latin name reads in the right order, in the preview and the file alike.
 */
const readingDirection = (): CanvasDirection => {
    if (document.documentElement.dir === 'rtl') {
        return 'rtl';
    }

    return 'ltr';
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
    context.direction = readingDirection();
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
