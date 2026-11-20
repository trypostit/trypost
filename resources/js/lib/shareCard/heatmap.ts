import { filled } from '@/lib/helpers';
import { ink, lightColor, muted } from '@/lib/shareCard/colors';
import { paintIdentity } from '@/lib/shareCard/frame';
import { ellipsise, writeText } from '@/lib/shareCard/text';
import { heading, IDENTITY, PANEL, sans } from '@/lib/shareCard/theme';
import type {
    Box,
    ShareCardOptions,
    ShareHeatmap,
} from '@/lib/shareCard/types';

/**
 * The posting activity card: the three numbers under the identity, and the
 * year's grid under them, one square per day in the card's tint, the way the
 * page draws it at twice the size.
 */
const HEATMAP = {
    statsTop: 178,
    statLabelSize: 22,
    statFigureSize: 60,
    statFigureGap: 66,
    gridTop: 300,
    monthSize: 18,
    monthGap: 12,
    rows: 7,
    gapShare: 0.2,
    radiusShare: 0.2,
};

/** How strong each level is, lightest to strongest, like the page's four steps of one color. */
const LEVEL_ALPHAS = [0.3, 0.55, 0.8, 1];

/** The step a day's count takes on a scale from one post to the busiest day, as the page's quantized scale does. */
const levelOf = (count: number, maxCount: number): number => {
    if (maxCount <= 1) {
        return LEVEL_ALPHAS.length - 1;
    }

    const share = (count - 1) / (maxCount - 1);

    return Math.min(
        LEVEL_ALPHAS.length - 1,
        Math.floor(share * LEVEL_ALPHAS.length),
    );
};

/** The three numbers side by side, label over figure, like the page's stat cells. */
const paintStats = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    inner: Box,
): void => {
    const width = inner.width / Math.max(options.tiles.length, 1);
    const labelFont = sans(500, HEATMAP.statLabelSize);
    const figureFont = heading(500, HEATMAP.statFigureSize);

    options.tiles.forEach((tile, index) => {
        const left = inner.x + width * index;
        const limit = width - PANEL.padding / 2;

        writeText(
            context,
            ellipsise(context, tile.label, labelFont, limit),
            { x: left, y: inner.y },
            { font: labelFont, color: muted() },
        );
        writeText(
            context,
            ellipsise(context, tile.figure, figureFont, limit),
            { x: left, y: inner.y + HEATMAP.statFigureGap },
            {
                font: figureFont,
                color: ink(),
            },
        );
    });
};

/** The year's squares: the widest square that fits the panel, every week a column, month names over the weeks they start in. */
const paintGrid = (
    context: CanvasRenderingContext2D,
    heatmap: ShareHeatmap,
    color: string,
    area: Box,
): void => {
    const columns = Math.ceil(
        (heatmap.counts.length + heatmap.offset) / HEATMAP.rows,
    );
    const stride = Math.min(area.width / columns, area.height / HEATMAP.rows);
    const top = area.y + area.height - stride * HEATMAP.rows;
    const gap = stride * HEATMAP.gapShare;
    const cell = stride - gap;
    const empty = lightColor('var(--muted)');
    const monthFont = sans(500, HEATMAP.monthSize);

    heatmap.months.forEach((month) => {
        writeText(
            context,
            month.label,
            { x: area.x + month.column * stride, y: top - HEATMAP.monthGap },
            { font: monthFont, color: muted() },
        );
    });

    heatmap.counts.forEach((count, index) => {
        const slot = index + heatmap.offset;
        const x = area.x + Math.floor(slot / HEATMAP.rows) * stride;
        const y = top + (slot % HEATMAP.rows) * stride;

        context.beginPath();
        context.roundRect(x, y, cell, cell, cell * HEATMAP.radiusShare);
        context.globalAlpha = 1;
        context.fillStyle = empty;

        if (count > 0) {
            context.globalAlpha =
                LEVEL_ALPHAS[levelOf(count, heatmap.maxCount)];
            context.fillStyle = color;
        }

        context.fill();
    });

    context.globalAlpha = 1;
};

export const paintHeatmapCard = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    panel: Box,
): void => {
    const inner = {
        x: panel.x + PANEL.padding,
        width: panel.width - PANEL.padding * 2,
    };

    paintIdentity(
        context,
        options.subject,
        `${options.label} · ${options.range}`,
        { x: inner.x, y: panel.y + IDENTITY.top },
        inner.width,
    );
    paintStats(context, options, {
        ...inner,
        y: panel.y + HEATMAP.statsTop,
        height: 0,
    });

    if (!filled(options.heatmap)) {
        return;
    }

    paintGrid(context, options.heatmap, options.tint.strong, {
        ...inner,
        y: panel.y + HEATMAP.gridTop,
        height: panel.height - HEATMAP.gridTop - PANEL.padding,
    });
};
