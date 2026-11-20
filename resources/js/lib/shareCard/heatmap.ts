import { filled } from '@/lib/helpers';
import { ink, lightColor, muted } from '@/lib/shareCard/colors';
import { paintIdentity } from '@/lib/shareCard/frame';
import { iconPaths } from '@/lib/shareCard/icons';
import { ellipsise, writeText } from '@/lib/shareCard/text';
import { heading, IDENTITY, PANEL, sans } from '@/lib/shareCard/theme';
import type {
    Box,
    ShareCardOptions,
    ShareHeatmap,
    ShareTile,
    Point,
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
    statIcon: 50,
    statIconGap: 15,
    statGap: 80,
    capShare: 0.7,
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

/** The width a tile takes: its label or its icon and figure, whichever is wider. */
const statWidth = (
    context: CanvasRenderingContext2D,
    tile: ShareTile,
    fonts: { label: string; figure: string },
): number => {
    context.font = fonts.label;

    const label = context.measureText(tile.label).width;

    context.font = fonts.figure;

    return Math.max(label, iconWidth(tile) + context.measureText(tile.figure).width);
};

/** The room a tile's icon and its gap take before the figure; none without an icon. */
const iconWidth = (tile: ShareTile): number => {
    if (!filled(tile.icon)) {
        return 0;
    }

    return HEATMAP.statIcon + HEATMAP.statIconGap;
};

/** A tile's icon, centred on its figure's capitals. */
const paintStatIcon = (
    context: CanvasRenderingContext2D,
    tile: ShareTile,
    at: Point,
): void => {
    if (!filled(tile.icon)) {
        return;
    }

    const size = HEATMAP.statIcon;

    context.save();
    context.translate(at.x, at.y - HEATMAP.statFigureSize * HEATMAP.capShare / 2 - size / 2);
    context.scale(size / 24, size / 24);
    context.fillStyle = lightColor(tile.icon.color);
    iconPaths(tile.icon.icon).forEach((path) => context.fill(new Path2D(path)));
    context.restore();
};

/** The three numbers one after another, label over icon and figure, like the page's stats. */
const paintStats = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    inner: Box,
): void => {
    const fonts = {
        label: sans(500, HEATMAP.statLabelSize),
        figure: heading(500, HEATMAP.statFigureSize),
    };
    const count = Math.max(options.tiles.length, 1);
    const limit = (inner.width - HEATMAP.statGap * (count - 1)) / count;
    let left = inner.x;

    options.tiles.forEach((tile) => {
        const figureY = inner.y + HEATMAP.statFigureGap;
        const figureX = left + iconWidth(tile);

        writeText(
            context,
            ellipsise(context, tile.label, fonts.label, limit),
            { x: left, y: inner.y },
            { font: fonts.label, color: muted() },
        );
        paintStatIcon(context, tile, { x: left, y: figureY });
        writeText(
            context,
            ellipsise(context, tile.figure, fonts.figure, limit - iconWidth(tile)),
            { x: figureX, y: figureY },
            { font: fonts.figure, color: ink() },
        );

        left += Math.min(statWidth(context, tile, fonts), limit) + HEATMAP.statGap;
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
