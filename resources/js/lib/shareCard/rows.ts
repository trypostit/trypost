import { AVATAR, avatarSpace, paintChannel, rowColor } from './channel';
import { ink, muted } from './colors';
import { fillBar, paintGrid } from './draw';
import { headlinePlot, paintHeadline, pointValues } from './frame';
import { valueAxis } from './scale';
import { ellipsise, widest, writeText } from './text';
import { CHART, PLOT, sans } from './theme';
import type { Box, ShareCardOptions, ShareImages, SharePoint } from './types';

/** One bar per channel after a column of its name, number and avatar, as wide as they need; a name past `nameMax` is trimmed. Bars take 60% of a row, 40px at most: the page's 20px at 2x. */
const ROWS = {
    max: 12,
    nameMax: 320,
    figureGap: 32,
    labelGap: 24,
    axis: 44,
    valuesGap: 36,
    thickness: 40,
    thicknessShare: 0.6,
    label: { min: 14, max: 22, share: 0.55 },
    figure: { min: 16, max: 26, share: 0.6 },
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

/** A row's text size: its share of the row's height, kept between the size's bounds. */
const rowTextSize = (size: { min: number; max: number; share: number }, rowHeight: number): number =>
    Math.min(size.max, Math.max(size.min, rowHeight * size.share));

/**
 * One horizontal bar per channel from zero, losses to the left, in each
 * channel's color, after its name, its number and its avatar in a fixed
 * column, like the page's Bar and Growth views.
 */
const paintRows = (context: CanvasRenderingContext2D, options: ShareCardOptions, images: ShareImages, area: Box): void => {
    const rows = rankedRows(options.points);
    const axis = valueAxis(rows.map((point) => point.value), CHART.valueTicks);
    const plotHeight = area.height - ROWS.axis;
    const rowHeight = plotHeight / Math.max(rows.length, 1);
    const thickness = Math.min(rowHeight * ROWS.thicknessShare, ROWS.thickness);
    const labelFont = sans(500, rowTextSize(ROWS.label, rowHeight));
    const figureFont = sans(600, rowTextSize(ROWS.figure, rowHeight));
    const avatarSize = Math.min(AVATAR.size, rowHeight * AVATAR.fill);
    const nameWidth = Math.min(ROWS.nameMax, widest(context, rows.map((point) => point.label), labelFont));
    const figureWidth = widest(context, rows.map((point) => point.figure ?? ''), figureFont);
    const channelWidth = Math.max(0, ...rows.map((point) => avatarSpace(point, avatarSize)));
    const column = nameWidth + ROWS.figureGap + figureWidth + channelWidth + ROWS.labelGap;
    const plot = { x: area.x + column, y: area.y, width: area.width - column, height: plotHeight };
    const x = (value: number): number => plot.x + axis.at(value) * plot.width;

    paintGrid(
        context,
        axis.ticks,
        (value) => [{ x: x(value), y: plot.y }, { x: x(value), y: plot.y + plot.height }],
        (value) => ({ x: x(value), y: plot.y + plot.height + ROWS.valuesGap }),
        { align: 'center' },
    );

    rows.forEach((point, row) => {
        const centre = plot.y + rowHeight * (row + 0.5);

        writeText(context, ellipsise(context, point.label, labelFont, nameWidth), { x: area.x, y: centre }, { font: labelFont, color: muted(), baseline: 'middle' });

        if (point.figure) {
            writeText(context, point.figure, { x: area.x + nameWidth + ROWS.figureGap + figureWidth, y: centre }, { font: figureFont, color: ink(), align: 'right', baseline: 'middle' });
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

/** The headline over one bar per channel, which takes the gutter for its names and numbers. */
export const paintRowsCard = (context: CanvasRenderingContext2D, options: ShareCardOptions, images: ShareImages, panel: Box): void => {
    paintHeadline(context, options, panel);

    if (pointValues(options.points).length === 0) {
        return;
    }

    const plot = headlinePlot(panel);

    paintRows(context, options, images, { x: plot.x - PLOT.gutter, y: plot.y, width: plot.width + PLOT.gutter, height: plot.height + PLOT.dates });
};
