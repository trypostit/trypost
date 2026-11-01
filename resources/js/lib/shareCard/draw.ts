import type { MetricDelta } from '@/lib/channelMetrics';
import { changeColor, gridColor, muted } from '@/lib/shareCard/colors';
import { traceMonotone } from '@/lib/shareCard/curve';
import { type TextStyle, widest, writeText } from '@/lib/shareCard/text';
import { CHANGE_ICONS, CHANGE_TOKENS, CHART, PLOT, sans } from '@/lib/shareCard/theme';
import type { Box, Point, SharePoint } from '@/lib/shareCard/types';
import { distinctCountLabels } from '@/lib/utils';

export const strokeLine = (context: CanvasRenderingContext2D, from: Point, to: Point): void => {
    context.beginPath();
    context.moveTo(from.x, from.y);
    context.lineTo(to.x, to.y);
    context.stroke();
};

/** `MetricChange`'s trend icon: its size and the gap after it as shares of the label's, and its stroke on the 24px icon grid. */
export const CHANGE_ICON = { scale: 1.15, gap: 0.3, stroke: 2.5, grid: 24 };

/** How far an anchor sits from a change's left edge, as a share of its width. */
const ALIGN_SHIFT = { left: 0, right: 1 } as const;

/** Which way a change points: up for a gain, down for a loss, flat when nothing moved. */
const changeDirection = (change: MetricDelta): keyof typeof CHANGE_TOKENS => {
    if (change.value > 0) {
        return 'up';
    }

    if (change.value < 0) {
        return 'down';
    }

    return 'flat';
};

/** How wide a change is drawn at `size`: its trend icon, a gap, then its label. */
export const changeWidth = (context: CanvasRenderingContext2D, change: MetricDelta, size: number): number => {
    context.font = sans(600, size);

    return size * (CHANGE_ICON.scale + CHANGE_ICON.gap) + context.measureText(change.label).width;
};

/** The change as the page's `MetricChange`: the trending icon and the label, in the tone's color. */
export const paintChange = (
    context: CanvasRenderingContext2D,
    change: MetricDelta,
    anchor: Point,
    size: number,
    align: 'left' | 'right',
): void => {
    const direction = changeDirection(change);
    const color = changeColor(direction);
    const font = sans(600, size);
    const icon = size * CHANGE_ICON.scale;
    const gap = size * CHANGE_ICON.gap;
    const left = anchor.x - changeWidth(context, change, size) * ALIGN_SHIFT[align];

    context.save();
    context.translate(left, anchor.y);
    context.scale(icon / CHANGE_ICON.grid, icon / CHANGE_ICON.grid);
    context.strokeStyle = color;
    context.lineWidth = CHANGE_ICON.stroke;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    CHANGE_ICONS[direction].forEach((path) => context.stroke(new Path2D(path)));
    context.restore();

    writeText(context, change.label, { x: left + icon + gap, y: anchor.y + icon / 2 + 1 }, { font, color, baseline: 'middle' });
};

/** How much room tick labels take beside the plot: their widest and the gap to the plot, at least the gutter's width. */
export const tickRoom = (context: CanvasRenderingContext2D, labels: string[]): number =>
    Math.max(PLOT.gutter, widest(context, labels, CHART.tickFont) + PLOT.tickGap);

/** The plot moved past its value ticks' labels, so a long one ("1,234,560") never runs over the panel's edge. */
export const besideTicks = (context: CanvasRenderingContext2D, plot: Box, labels: string[]): Box => {
    const extra = tickRoom(context, labels) - PLOT.gutter;

    return { ...plot, x: plot.x + extra, width: plot.width - extra };
};

/** The page's dashed grid lines. */
export const strokeGrid = (context: CanvasRenderingContext2D, lines: [Point, Point][]): void => {
    context.save();
    context.setLineDash(CHART.gridDash);
    context.lineWidth = CHART.gridWidth;
    context.strokeStyle = gridColor();
    lines.forEach((line) => strokeLine(context, ...line));
    context.restore();
};

/** A muted tick label of an axis. */
export const writeTick = (context: CanvasRenderingContext2D, label: string, at: Point, style: Pick<TextStyle, 'align' | 'baseline'>): void => {
    writeText(context, label, at, { font: CHART.tickFont, color: muted(), ...style });
};

/** The dashed value grid: one line per tick, with the tick's value at `label`. */
export const paintGrid = (
    context: CanvasRenderingContext2D,
    ticks: number[],
    line: (value: number) => [Point, Point],
    label: (value: number) => Point,
    style: Pick<TextStyle, 'align' | 'baseline'>,
): void => {
    const labels = distinctCountLabels(ticks);

    strokeGrid(context, ticks.map(line));
    ticks.forEach((value, index) => writeTick(context, labels[index], label(value), style));
};

/** The radius at a bar's low-coordinate edge and at its high one: only the data end is round. */
const dataEndRadii = (start: number, end: number, radius: number): [number, number] => {
    if (end > start) {
        return [0, radius];
    }

    return [radius, 0];
};

/**
 * A bar from the baseline (`start`) to its value (`end`) along its axis,
 * centred on `middle` across it and rounded only at the data end, like
 * unovis' `roundedCorners`.
 */
export const fillBar = (
    context: CanvasRenderingContext2D,
    start: number,
    end: number,
    middle: number,
    thickness: number,
    horizontal: boolean,
): void => {
    const length = Math.abs(end - start);
    const radius = Math.min(thickness * CHART.barRoundness, length);
    const [low, high] = dataEndRadii(start, end, radius);

    context.beginPath();

    if (horizontal) {
        context.roundRect(Math.min(start, end), middle - thickness / 2, length, thickness, [low, high, high, low]);
    } else {
        context.roundRect(middle - thickness / 2, Math.min(start, end), thickness, length, [low, low, high, high]);
    }

    context.fill();
};

/** The page's 2px monotone line through `points`, dashed when `dash` says so. */
export const strokeCurve = (context: CanvasRenderingContext2D, points: Point[], color: string, dash: number[] = []): void => {
    context.save();
    context.strokeStyle = color;
    context.lineWidth = CHART.lineWidth;
    context.lineJoin = 'round';
    context.lineCap = 'round';
    context.setLineDash(dash);
    traceMonotone(context, points);
    context.stroke();
    context.restore();
};

/**
 * A line's runs of points with a value, where the plot draws them: a missing
 * value breaks the line there, as unovis' line does on the page.
 */
export const definedRuns = (values: (number | null)[], x: (index: number) => number, y: (value: number) => number): Point[][] =>
    values
        .reduce<Point[][]>(
            (runs, value, index) => {
                if (value === null) {
                    return [...runs, []];
                }

                runs[runs.length - 1].push({ x: x(index), y: y(value) });

                return runs;
            },
            [[]],
        )
        .filter((run) => run.length > 0);

/** The dates of the points at `indexes`, under the plot. */
export const paintDates = (
    context: CanvasRenderingContext2D,
    points: SharePoint[],
    indexes: number[],
    x: (index: number) => number,
    plot: Box,
): void => {
    indexes.forEach((index) => writeTick(context, points[index]?.label ?? '', { x: x(index), y: plot.y + plot.height + PLOT.dates }, { align: 'center' }));
};
