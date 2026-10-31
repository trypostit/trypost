import type { MetricDelta } from '@/lib/channelMetrics';

import { ink, muted } from './colors';
import { CHANGE_ICON, changeWidth, paintChange, strokeLine } from './draw';
import { paintIdentity } from './frame';
import { ellipsise, writeText } from './text';
import { BORDER, heading, IDENTITY, PANEL, sans } from './theme';
import type { Box, Point, ShareCardOptions, ShareTile } from './types';

/** The Summary's grid under the identity; a tile is drawn for `tileHeight` and shrinks to fit. */
const SUMMARY = { top: 168, tileHeight: 185, inset: 36, crowdedInset: 24, crowdedColumns: 5, label: 50, labelSize: 24, figureGap: 6, changeGap: 16, changeSize: 24, changeBelow: 12, minTextShare: 0.7, figureOverhang: 2, crowdedFigure: 52, singleRowFigure: 84, figure: 64, crowdedFrom: 4 };

/** A tile's number in ink, or muted when the metric has no value yet. */
const tileFigureColor = (tile: ShareTile): string => {
    if (tile.missing) {
        return muted();
    }

    return ink();
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
export const paintSummary = (context: CanvasRenderingContext2D, options: ShareCardOptions, panel: Box): void => {
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

    context.strokeStyle = ink();
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
        const figureText = tile.figure;
        const figureFont = heading(500, fittingSize(context, figureText, (size) => heading(500, size), figureSize, limit));
        const baseline = labelLine + figureSize + SUMMARY.figureGap * fit;

        writeText(context, ellipsise(context, tile.label, labelFont, limit), { x: left, y: labelLine }, { font: labelFont, color: muted() });
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

        return context.measureText(tile.figure).width + gap + changeWidth(context, tile.change, changeSize) > limit(index);
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
