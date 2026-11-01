
import { surface } from '@/lib/shareCard/colors';
import { barWidth } from '@/lib/shareCard/combo';
import { besideTicks, fillBar, paintDates, paintGrid } from '@/lib/shareCard/draw';
import { headlinePlot, paintHeadline, pointValues } from '@/lib/shareCard/frame';
import { fittingColumns, valueAxis } from '@/lib/shareCard/scale';
import { widest } from '@/lib/shareCard/text';
import { CHART, PLOT } from '@/lib/shareCard/theme';
import type { Box, ShareCardOptions, SharePoint, ShareSegment } from '@/lib/shareCard/types';
import { distinctCountLabels } from '@/lib/utils';

type Column = { middle: number; width: number };

/**
 * A column split by channel, bottom up in the page's order, with only its top
 * rounded like unovis' stacked bars.
 */
/** The page's Posts columns at 2x: 28px at most, each channel's part set apart by a 1.5px line of the card's white. */
const STACKED = { barMaxWidth: 56, separator: 3 };

/** The card's white line between two channels' parts of a column, where the lower one ends. */
const paintSeparator = (context: CanvasRenderingContext2D, column: Column, at: number): void => {
    context.fillStyle = surface();
    context.fillRect(column.middle - column.width / 2, at - STACKED.separator / 2, column.width, STACKED.separator);
};

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

    parts.slice(0, -1).reduce((boundary, segment) => {
        const top = boundary + segment.value;

        paintSeparator(context, column, y(top));

        return top;
    }, 0);
};

/** One column per bucket from zero, split by channel in each channel's color. */
const paintBars = (
    context: CanvasRenderingContext2D,
    options: ShareCardOptions,
    plot: Box,
    x: (index: number) => number,
    y: (value: number) => number,
): void => {
    const width = barWidth(plot.width / options.points.length, 1, STACKED.barMaxWidth);

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
    return fittingColumns(points.length, Math.floor(width / room));
};

/** The headline over one column per bucket, split by channel. */
export const paintStackedCard = (context: CanvasRenderingContext2D, options: ShareCardOptions, panel: Box): void => {
    paintHeadline(context, options, panel);

    const values = pointValues(options.points);

    if (values.length === 0) {
        return;
    }

    const axis = valueAxis(values, CHART.valueTicks);
    const plot = besideTicks(context, headlinePlot(panel), distinctCountLabels(axis.ticks));
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
