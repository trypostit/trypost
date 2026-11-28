import { ink, lightColor, muted } from '@/lib/app/shareCard/colors';
import { paintIdentity } from '@/lib/app/shareCard/frame';
import { paintIcon } from '@/lib/app/shareCard/icons';
import { quantizeLevel } from '@/lib/app/shareCard/scale';
import { widest, writeText } from '@/lib/app/shareCard/text';
import { heading, IDENTITY, PANEL, sans } from '@/lib/app/shareCard/theme';
import type {
    Box,
    Point,
    ShareCardOptions,
    ShareHeatmap,
    ShareTile,
} from '@/lib/app/shareCard/types';
import { filled } from '@/lib/helpers';

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

/** The numbers' fonts and icon at `fit` of their full size, so three long figures shrink together instead of being cut. */
type StatSizes = {
    label: string;
    figure: string;
    figureSize: number;
    icon: number;
    iconGap: number;
    gap: number;
};

const statSizes = (fit: number): StatSizes => ({
    label: sans(500, HEATMAP.statLabelSize * fit),
    figure: heading(500, HEATMAP.statFigureSize * fit),
    figureSize: HEATMAP.statFigureSize * fit,
    icon: HEATMAP.statIcon * fit,
    iconGap: HEATMAP.statIconGap * fit,
    gap: HEATMAP.statGap * fit,
});

/** The room a tile's icon and its gap take before the figure; none without an icon. */
const iconWidth = (tile: ShareTile, sizes: StatSizes): number => {
    if (!filled(tile.icon)) {
        return 0;
    }

    return sizes.icon + sizes.iconGap;
};

/** The width a tile takes: its label or its icon and figure, whichever is wider. */
const statWidth = (
    context: CanvasRenderingContext2D,
    tile: ShareTile,
    sizes: StatSizes,
): number => {
    context.font = sizes.label;

    const label = context.measureText(tile.label).width;

    context.font = sizes.figure;

    return Math.max(
        label,
        iconWidth(tile, sizes) + context.measureText(tile.figure).width,
    );
};

/** How much the numbers shrink so all of them fit the panel's width; never grown past their full size. */
const statsFit = (
    context: CanvasRenderingContext2D,
    tiles: ShareTile[],
    width: number,
): number => {
    const full = statSizes(1);
    const needed =
        tiles.reduce(
            (total, tile) => total + statWidth(context, tile, full),
            0,
        ) +
        full.gap * Math.max(tiles.length - 1, 0);

    if (needed <= width) {
        return 1;
    }

    return width / needed;
};

/** A tile's icon, centred on its figure's capitals. */
const paintStatIcon = (
    context: CanvasRenderingContext2D,
    tile: ShareTile,
    sizes: StatSizes,
    at: Point,
): void => {
    if (!filled(tile.icon)) {
        return;
    }

    paintIcon(
        context,
        tile.icon.icon,
        lightColor(tile.icon.color),
        {
            x: at.x,
            y:
                at.y -
                (sizes.figureSize * HEATMAP.capShare) / 2 -
                sizes.icon / 2,
        },
        sizes.icon,
    );
};

/** The numbers one after another, label over icon and figure, like the page's stats, shrunk together when they would not fit. */
const paintStats = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    inner: Box,
): void => {
    const sizes = statSizes(statsFit(context, options.tiles, inner.width));
    const figureY = inner.y + HEATMAP.statFigureGap;
    let left = inner.x;

    options.tiles.forEach((tile) => {
        writeText(
            context,
            tile.label,
            { x: left, y: inner.y },
            { font: sizes.label, color: muted() },
        );
        paintStatIcon(context, tile, sizes, { x: left, y: figureY });
        writeText(
            context,
            tile.figure,
            { x: left + iconWidth(tile, sizes), y: figureY },
            { font: sizes.figure, color: ink() },
        );

        left += statWidth(context, tile, sizes) + sizes.gap;
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

    const gridRight = area.x + columns * stride - gap;

    heatmap.months.forEach((month) => {
        const labelWidth = widest(context, [month.label], monthFont);

        writeText(
            context,
            month.label,
            {
                x: Math.min(
                    area.x + month.column * stride,
                    gridRight - labelWidth,
                ),
                y: top - HEATMAP.monthGap,
            },
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
                LEVEL_ALPHAS[
                    quantizeLevel(count, heatmap.maxCount, LEVEL_ALPHAS.length)
                ];
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
