import { CHART } from './theme';

/** d3's tick rule: the step is 10, 5, 2 or 1 times a power of ten, whichever lands closest to the asked count. */
const TICK_FACTORS: [number, number][] = [
    [Math.sqrt(50), 10],
    [Math.sqrt(10), 5],
    [Math.sqrt(2), 2],
];

/** The tick step's factor for how far the raw step is past its power of ten: 1 below every threshold. */
const tickFactor = (error: number): number => {
    const match = TICK_FACTORS.find(([threshold]) => error >= threshold);

    if (!match) {
        return 1;
    }

    return match[1];
};

/** How far `value` sits from `low` to `high`, 0 to 1; a flat range puts everything at its low end's height. */
export const shareOf = (value: number, low: number, high: number): number => (value - low) / (high - low || 1);

type ValueAxis = {
    ticks: number[];
    /** Where a value sits on the axis, from 0 at its start to 1 at its end. */
    at: (value: number) => number;
    /** Where the bars start: zero. */
    zero: number;
};

/**
 * A value axis like the page's unovis axes: the values' range with zero in
 * it, not rounded out, and d3's round ticks inside it.
 */
export const valueAxis = (values: number[], tickCount: number): ValueAxis => {
    const low = Math.min(0, ...values);
    const high = Math.max(0, ...values);
    const at = (value: number): number => shareOf(value, low, high);

    return { ticks: linearTicks(low, high, tickCount), at, zero: at(0) };
};

/** Where the `index`-th of `count` points sits across a plot, the first at its start and the last at its end, like unovis' line. */
export const spread = (index: number, count: number): number => {
    if (count <= 1) {
        return 0.5;
    }

    return index / (count - 1);
};

/** Round ticks inside [start, stop], about `count` of them, the way d3 (and so the page's axes) picks them. */
export const linearTicks = (start: number, stop: number, count: number): number[] => {
    if (count <= 0 || stop < start) {
        return [];
    }

    if (stop === start) {
        return [start];
    }

    const raw = (stop - start) / count;
    const power = 10 ** Math.floor(Math.log10(raw));
    const error = raw / power;
    const step = power * tickFactor(error);
    const first = Math.ceil(start / step);
    const last = Math.floor(stop / step);

    return Array.from({ length: last - first + 1 }, (_, index) => (first + index) * step);
};

/** Up to `CHART.maxDates` dates evenly spread over a line, like the page's date axes. */
export const evenDates = (count: number): number[] => linearTicks(0, count - 1, Math.min(count, CHART.maxDates));
