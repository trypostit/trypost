import { barWidth } from './combo';
import { fillBar, paintDates, paintGrid } from './draw';
import { headlinePlot, paintHeadline, pointValues } from './frame';
import { valueAxis } from './scale';
import { widest } from './text';
import { CHART, PLOT } from './theme';
import type { Box, ShareCardOptions, SharePoint, ShareSegment } from './types';

type Column = { middle: number; width: number };

/**
 * A column split by channel, bottom up in the page's order, with only its top
 * rounded like unovis' stacked bars.
 */
const paintStack = (
    context: CanvasRenderingContext2D,
    segments: ShareSegment[],
    column: Column,
    y: (value: number) => number,
): void => {
    const parts = segments.filter((segment) => segment.value > 0);
    let base = 0;

    parts.forEach((segment, index) => {
        const top = base + segment.value;

        context.fillStyle = segment.color;

        if (index === parts.length - 1) {
            fillBar(context, y(base), y(top), column.middle, column.width, false);
        } else {
            context.fillRect(column.middle - column.width / 2, y(top), column.width, y(base) - y(top));
        }

        base = top;
    });
};

/** One column per bucket from zero, split by channel in each channel's color. */
const paintBars = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    plot: Box,
    x: (index: number) => number,
    y: (value: number) => number,
): void => {
    const width = barWidth(plot.width / options.points.length, 1);

    options.points.forEach((point, index) => {
        if (point.segments && point.value) {
            paintStack(context, point.segments, { middle: x(index), width }, y);
        }
    });
};

/**
 * The columns that get a date: every one, like the page's Posts chart, unless
 * the card is too narrow for them all, then every second or third, so no two
 * dates ever overlap.
 */
const columnDates = (context: CanvasRenderingContext2D, points: SharePoint[], width: number): number[] => {
    const room = widest(context, points.map((point) => point.label), CHART.tickFont) + PLOT.dateGap;
    const fitting = Math.max(Math.floor(width / room), 1);

    const step = Math.ceil(points.length / fitting);

    return points.map((_, index) => index).filter((index) => index % step === 0);
};

/** The headline over one column per bucket, split by channel. */
export const paintStackedCard = (context: CanvasRenderingContext2D, options: ShareCardOptions, panel: Box): void => {
    paintHeadline(context, options, panel);

    const values = pointValues(options.points);

    if (values.length === 0) {
        return;
    }

    const plot = headlinePlot(panel);
    const axis = valueAxis(values, CHART.valueTicks);
    const slot = plot.width / options.points.length;
    const x = (index: number): number => plot.x + slot * (index + 0.5);
    const y = (value: number): number => plot.y + plot.height - axis.at(value) * plot.height;

    paintGrid(
        context,
        axis.ticks,
        (value) => [{ x: plot.x, y: y(value) }, { x: plot.x + plot.width, y: y(value) }],
        (value) => ({ x: plot.x - PLOT.tickGap, y: y(value) }),
        { align: 'right', baseline: 'middle' },
    );
    paintBars(context, options, plot, x, y);
    paintDates(context, options.points, columnDates(context, options.points, plot.width), x, plot);
};
