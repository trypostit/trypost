import {
    AXIS_STEPS,
    axisDomain,
    axisTickLabels,
    type ChartMode,
    chartPeriods,
    type MetricDelta,
} from '@/lib/channelMetrics';
import type { PublicationPeriod } from '@/types/analytics';

import {
    changeWidth,
    definedRuns,
    fillBar,
    paintChange,
    paintDates,
    strokeCurve,
    strokeGrid,
    writeTick,
} from './draw';
import { paintIdentity } from './frame';
import { evenDates, shareOf } from './scale';
import { widest, writeText } from './text';
import { CHART, heading, IDENTITY, INK, MUTED, PANEL, PLOT, sans } from './theme';
import type { Box, Point, ShareCardOptions, ShareSeries } from './types';

/** The previous period's hatch: a tile of the color's light wash with one stripe across it, turned 45°. */
const HATCH = { size: 10, wash: 0.12, stripe: 0.4, angle: 45 };

/**
 * How wide each bar of a `slot` is: one bar like the page's, or, with both
 * periods, two bars side by side that fill the day with a small gap between
 * them instead of halving a single bar.
 */
export const barWidth = (slot: number, bars: number): number =>
    Math.max(Math.min(barsRoom(slot, bars) / bars, CHART.barMaxWidth), CHART.barMinWidth);

/** The room a slot's bars share: a single bar keeps the page's padding, a pair fills the day but for the gap between them. */
const barsRoom = (slot: number, bars: number): number => {
    if (bars === 1) {
        return slot * (1 - CHART.barPadding);
    }

    return slot * (1 - COMBO.groupPadding) - COMBO.pairGap * (bars - 1);
};

/** The channel chart at 2x: its legend's label, figure and swatch, and its 16px bars. */
const COMBO = { legendGap: 56, identityShare: 0.5, labelSize: 22, figureSize: 52, figureLine: 52, swatchRise: 0.55, swatch: 18, swatchGap: 12, changeSize: 22, changeGap: 14, changeBelow: 14, rightGutter: 72, groupPadding: 0.1, pairGap: 4, dash: [10, 8] };

/** The page's hatch for a previous period's bars: stripes of the color over a light wash of it. */
const hatchPattern = (context: CanvasRenderingContext2D, color: string): CanvasPattern | string => {
    const tile = document.createElement('canvas');
    const size = HATCH.size;

    tile.width = size;
    tile.height = size;

    const stripes = tile.getContext('2d');

    if (!stripes) {
        return color;
    }

    stripes.fillStyle = color;
    stripes.globalAlpha = HATCH.wash;
    stripes.fillRect(0, 0, size, size);
    stripes.globalAlpha = 1;
    stripes.fillRect(0, 0, size * HATCH.stripe, size);

    const pattern = context.createPattern(tile, 'repeat');

    if (!pattern) {
        return color;
    }

    pattern.setTransform(new DOMMatrix().rotate(HATCH.angle));

    return pattern;
};

/** The page's fill for a period's bars: the previous period always hatched. */
const periodFill = (context: CanvasRenderingContext2D, color: string, period: PublicationPeriod): CanvasPattern | string => {
    if (period === 'previous') {
        return hatchPattern(context, color);
    }

    return color;
};

/** The page's stroke for a period's line: the previous period always dashed. */
const periodDash = (period: PublicationPeriod): number[] => {
    if (period === 'previous') {
        return COMBO.dash;
    }

    return [];
};

/** The legend swatch's corner, and the dashed outline that stands for a previous period's line. */
const SWATCH = { radius: 4, dash: [4, 3], outline: 3 };

/** A legend swatch like the page's: solid for this period, hatched (bars) or a dashed outline (lines) for the previous one. */
const paintLegendSwatch = (context: CanvasRenderingContext2D, series: ShareSeries, color: string, period: PublicationPeriod, at: Point, size: number): void => {
    context.save();
    context.beginPath();
    context.roundRect(at.x, at.y, size, size, SWATCH.radius);

    if (period === 'previous' && series.kind === 'line') {
        context.setLineDash(SWATCH.dash);
        context.lineWidth = SWATCH.outline;
        context.strokeStyle = color;
        context.stroke();
        context.restore();

        return;
    }

    context.fillStyle = periodFill(context, color, period);
    context.fill();
    context.restore();
};

/** What a legend item prints in a chart mode: the previous period alone in Comparison, both periods in Both. */
const legendEntries = (series: ShareSeries, mode: ChartMode): { figure: string; period: PublicationPeriod }[] =>
    chartPeriods(mode).map((period) => ({ figure: periodFigure(series, period), period }));

/** A metric's figure over one period. */
export const periodFigure = (series: ShareSeries, period: PublicationPeriod): string => {
    if (period === 'previous') {
        return series.previousFigure;
    }

    return series.figure;
};

/** A legend figure's ink: the previous period's is muted next to the current one in Both, like the page's. */
const legendFigureColor = (period: PublicationPeriod, mode: ChartMode): string => {
    if (period === 'previous' && mode === 'both') {
        return MUTED;
    }

    return INK;
};

/** How wide a legend item's change is drawn; none without one. */
const legendChangeWidth = (context: CanvasRenderingContext2D, change: MetricDelta | null, size: number): number => {
    if (!change) {
        return 0;
    }

    return changeWidth(context, change, size);
};

/** The change a legend item shows: none in Comparison, which shows the previous period itself. */
const legendChange = (series: ShareSeries, mode: ChartMode): MetricDelta | null => {
    if (mode === 'previous') {
        return null;
    }

    return series.change;
};

/**
 * The page's legend on the headline's right: each metric's name over its
 * swatches and figures, its change under them, at `fit` of its full size.
 * Returns how wide it is.
 */
const paintComboLegend = (context: CanvasRenderingContext2D, options: ShareCardOptions, right: number, top: number, fit: number): number => {
    const size = (value: number): number => value * fit;
    const labelFont = sans(500, size(COMBO.labelSize));
    const figureFont = heading(500, size(COMBO.figureSize));
    let edge = right;

    [...options.series].reverse().forEach((series) => {
        const entries = legendEntries(series, options.chartMode);
        const change = legendChange(series, options.chartMode);

        context.font = figureFont;
        const entryWidths = entries.map((entry) => size(COMBO.swatch + COMBO.swatchGap) + context.measureText(entry.figure).width);
        const figuresWidth = entryWidths.reduce((total, width) => total + width, 0) + size(COMBO.changeGap) * (entries.length - 1);
        const changeRoom = legendChangeWidth(context, change, size(COMBO.changeSize));
        context.font = labelFont;
        const width = Math.max(context.measureText(series.label).width, figuresWidth, changeRoom);
        const left = edge - width;
        const baseline = top + size(COMBO.labelSize + COMBO.figureLine);
        const color = series.color;
        let cursor = left;

        writeText(context, series.label, { x: left, y: top + size(COMBO.labelSize) }, { font: labelFont, color: MUTED });

        entries.forEach((entry, position) => {
            paintLegendSwatch(context, series, color, entry.period, { x: cursor, y: baseline - size(COMBO.figureSize * COMBO.swatchRise) }, size(COMBO.swatch));
            writeText(context, entry.figure, { x: cursor + size(COMBO.swatch + COMBO.swatchGap), y: baseline }, { font: figureFont, color: legendFigureColor(entry.period, options.chartMode) });
            cursor += entryWidths[position] + size(COMBO.changeGap);
        });

        if (change) {
            paintChange(context, change, { x: left, y: baseline + size(COMBO.changeBelow) }, size(COMBO.changeSize), 'left');
        }

        edge = left - size(COMBO.legendGap);
    });

    return right - edge - size(COMBO.legendGap);
};

/**
 * How much of its full size the legend keeps: all of it while it leaves room
 * for the channel's name and period, else smaller, so a wide legend (both
 * periods, large figures) never cuts them. The name keeps at most
 * `COMBO.identityShare` of the headline, so the legend never vanishes.
 */
const legendFit = (options: ShareCardOptions, headline: number): number => {
    const scratch = document.createElement('canvas').getContext('2d');

    if (!scratch) {
        return 1;
    }

    const identity = Math.max(
        widest(scratch, [options.subject], heading(600, IDENTITY.nameSize)),
        widest(scratch, [options.range], sans(500, IDENTITY.detailSize)),
    );
    const full = paintComboLegend(scratch, options, headline, 0, 1);
    const room = headline - Math.min(identity, headline * COMBO.identityShare) - COMBO.legendGap;

    return Math.min(1, room / full);
};

/** The metrics drawn on an axis of the channel chart. */
const axisSeries = (options: ShareCardOptions, axis: ShareSeries['axis']): ShareSeries[] =>
    options.series.filter((series) => series.axis === axis);

/** An axis' domain over the periods shown, by the page's rule. */
const comboDomain = (series: ShareSeries[], periods: PublicationPeriod[]): { min: number; max: number } | null =>
    axisDomain(
        series.map((metric) => metric.metric),
        series.flatMap((metric) => periods.flatMap((period) => metric[period].filter((value): value is number => value !== null))),
    );

/** A period's values over the chart's days only, like the page, which lays the previous period over the current one's days. */
const shownValues = (series: ShareSeries, period: PublicationPeriod, days: number): (number | null)[] =>
    series[period].slice(0, days);

/** An axis' domain, or 0 to 1 for an axis no metric uses, so a value there still has a place. */
const domainOrUnit = (domain: { min: number; max: number } | null): { min: number; max: number } => {
    if (!domain) {
        return { min: 0, max: 1 };
    }

    return domain;
};

/** The room the right axis' labels take; none when no metric uses it. */
const rightAxisRoom = (domain: { min: number; max: number } | null): number => {
    if (domain) {
        return COMBO.rightGutter;
    }

    return 0;
};

/** Where an axis' tick labels sit: right-aligned before the plot on the left, left-aligned after it on the right. */
const AXIS_SIDES: Record<ShareSeries['axis'], { align: CanvasTextAlign; edge: (plot: Box) => number; direction: number }> = {
    left: { align: 'right', edge: (plot) => plot.x, direction: -1 },
    right: { align: 'left', edge: (plot) => plot.x + plot.width, direction: 1 },
};

/**
 * The channel chart as the page shows it: its metrics' bars and lines on a
 * left and a right axis, for the periods its toggle shows, the previous
 * period's bars hatched and its lines dashed, under the page's legend. The
 * legend names the metrics, so the line under the name keeps only the period.
 */
export const paintCombo = (context: CanvasRenderingContext2D, options: ShareCardOptions, panel: Box): void => {
    const left = panel.x + PANEL.padding;
    const right = panel.x + panel.width - PANEL.padding;
    const legendWidth = paintComboLegend(context, options, right, panel.y + IDENTITY.top, legendFit(options, right - left));

    paintIdentity(context, options.subject, options.range, { x: left, y: panel.y + IDENTITY.top }, right - left - legendWidth - COMBO.legendGap);

    const periods = chartPeriods(options.chartMode);
    const domains = {
        left: comboDomain(axisSeries(options, 'left'), periods),
        right: comboDomain(axisSeries(options, 'right'), periods),
    };
    const plot = {
        x: left + PLOT.gutter,
        y: panel.y + PLOT.top,
        width: right - left - PLOT.gutter - rightAxisRoom(domains.right),
        height: panel.height - PLOT.top - PLOT.bottom,
    };
    const count = Math.max(options.points.length, 1);
    const slot = plot.width / count;
    const x = (index: number): number => plot.x + slot * (index + 0.5);
    const y = (axis: 'left' | 'right', value: number): number => {
        const domain = domainOrUnit(domains[axis]);

        return plot.y + plot.height - shareOf(value, domain.min, domain.max) * plot.height;
    };
    const gridY = (step: number): number => plot.y + plot.height - (step / AXIS_STEPS) * plot.height;

    const steps = Array.from({ length: AXIS_STEPS + 1 }, (_, step) => step);

    strokeGrid(context, steps.map((step) => [{ x: plot.x, y: gridY(step) }, { x: plot.x + plot.width, y: gridY(step) }]));

    (['left', 'right'] as const).forEach((axis) => {
        const domain = domains[axis];

        if (!domain) {
            return;
        }

        const side = AXIS_SIDES[axis];
        const labels = axisTickLabels(domain, axisSeries(options, axis).map((series) => series.metric));

        labels.forEach((label, step) => {
            writeTick(context, label, { x: side.edge(plot) + side.direction * PLOT.tickGap, y: gridY(step) }, { align: side.align, baseline: 'middle' });
        });
    });

    const width = barWidth(slot, periods.length);
    const barStep = width + COMBO.pairGap * (periods.length - 1);

    options.series.filter((series) => series.kind === 'bar').forEach((series) => {
        const color = series.color;
        const zero = y(series.axis, Math.max(0, domainOrUnit(domains[series.axis]).min));

        periods.forEach((period, position) => {
            context.fillStyle = periodFill(context, color, period);

            shownValues(series, period, count).forEach((value, index) => {
                if (value === null || value === 0) {
                    return;
                }

                const middle = x(index) + (position - (periods.length - 1) / 2) * barStep;

                fillBar(context, zero, y(series.axis, value), middle, width, false);
            });
        });
    });

    options.series.filter((series) => series.kind === 'line').forEach((series) => {
        periods.forEach((period) => {
            definedRuns(shownValues(series, period, count), x, (value) => y(series.axis, value)).forEach((run) => {
                strokeCurve(context, run, series.color, periodDash(period));
            });
        });
    });

    paintDates(context, options.points, evenDates(count), x, plot);
};
