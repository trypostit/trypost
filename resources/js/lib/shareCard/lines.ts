import { spreadLabels } from '@/lib/labelLayout';
import { distinctCountLabels } from '@/lib/utils';

import { paintChannel } from './channel';
import { ink } from './colors';
import { besideTicks, definedRuns, paintDates, paintGrid, strokeCurve } from './draw';
import { headlinePlot, paintHeadline } from './frame';
import { evenDates, linearTicks, shareOf, spread } from './scale';
import { writeText } from './text';
import { CHART, PLOT, sans } from './theme';
import type { Box, Point, ShareCardOptions, ShareImages, ShareLine } from './types';

/**
 * The page's Line view at 2x: its 112px rail (a 32px leader, the 20px avatar,
 * a 6px gap and the number in 12px medium), 24px between labels, 16px above
 * the plot the labels may use, 5 value ticks and up to 6 dates.
 */
const LINE_ENDS = { rail: 224, leader: 64, leaderFrom: 1, leaderWidth: 3, leaderInset: 8, leaderTip: 4, avatar: 40, avatarShare: 0.85, gap: 12, height: 48, fontSize: 24, fontShare: 0.6, headroom: 32 };

/** One channel's line in its color, with a dot on each known point. */
const strokeSeries = (context: CanvasRenderingContext2D, line: ShareLine, x: (index: number) => number, y: (value: number) => number): Point[] => {
    const runs = definedRuns(line.values, x, y);
    const known = runs.flat();
    const color = line.color;

    runs.forEach((run) => strokeCurve(context, run, color));

    context.fillStyle = color;
    known.forEach((point) => {
        context.beginPath();
        context.arc(point.x, point.y, CHART.dotRadius, 0, Math.PI * 2);
        context.fill();
    });

    return known;
};

/**
 * One line per channel, like the page's Line view: each ends in the channel's
 * avatar and latest number on the right, spread apart with a leader in the
 * line's color where lines end close together. The labels may use from just
 * above the plot down to the zero line; with many channels they shrink to fit.
 */
const paintLines = (context: CanvasRenderingContext2D, options: ShareCardOptions, images: ShareImages, area: Box): void => {
    const values = options.lines.flatMap((line) => line.values.filter((value): value is number => value !== null));

    if (values.length === 0) {
        return;
    }

    const low = Math.min(...values);
    const high = Math.max(...values);
    const ticks = linearTicks(low, high, CHART.valueTicks);
    const plot = besideTicks(context, { ...area, width: area.width - LINE_ENDS.rail }, distinctCountLabels(ticks));
    const x = (index: number): number => plot.x + spread(index, options.points.length) * plot.width;
    const y = (value: number): number => plot.y + plot.height - shareOf(value, low, high) * plot.height;
    const lineEnd = plot.x + plot.width;

    paintGrid(
        context,
        ticks,
        (value) => [{ x: plot.x, y: y(value) }, { x: lineEnd, y: y(value) }],
        (value) => ({ x: plot.x - PLOT.tickGap, y: y(value) }),
        { align: 'right', baseline: 'middle' },
    );

    paintDates(context, options.points, evenDates(options.points.length), x, plot);

    const ends = options.lines.flatMap((line) => {
        const known = strokeSeries(context, line, x, y);
        const last = known[known.length - 1];

        return last ? [{ line, lineY: last.y }] : [];
    });
    const room = { top: plot.y - LINE_ENDS.headroom, bottom: Math.min(y(0), plot.y + plot.height) };
    const step = Math.min(LINE_ENDS.height, (room.bottom - room.top) / Math.max(ends.length, 1));
    const avatarSize = Math.min(LINE_ENDS.avatar, step * LINE_ENDS.avatarShare);
    const font = sans(500, Math.min(LINE_ENDS.fontSize, step * LINE_ENDS.fontShare));
    const labels = spreadLabels(
        ends.map((end) => end.lineY),
        { gap: step, ...room },
    );

    ends.forEach(({ line, lineY }, position) => {
        const labelY = labels[position];
        const labelX = lineEnd + LINE_ENDS.leader;

        if (Math.abs(labelY - lineY) > LINE_ENDS.leaderFrom) {
            context.strokeStyle = line.color;
            context.lineWidth = LINE_ENDS.leaderWidth;
            context.lineJoin = 'round';
            context.beginPath();
            context.moveTo(lineEnd + LINE_ENDS.leaderInset, lineY);
            context.lineTo(lineEnd + LINE_ENDS.leader / 2, lineY);
            context.lineTo(labelX - LINE_ENDS.leaderTip, labelY);
            context.stroke();
        }

        paintChannel(context, line.channel, images, { x: labelX, y: labelY - avatarSize / 2 }, avatarSize);
        writeText(context, line.figure, { x: labelX + avatarSize + LINE_ENDS.gap, y: labelY }, { font, color: ink(), baseline: 'middle' });
    });
};

/** The headline over one line per channel. */
export const paintLinesCard = (context: CanvasRenderingContext2D, options: ShareCardOptions, images: ShareImages, panel: Box): void => {
    paintHeadline(context, options, panel);
    paintLines(context, options, images, headlinePlot(panel));
};
