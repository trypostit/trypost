import { CHART } from '@/lib/shareCard/theme';

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

/** The ticks' run: the first and last multiples of `increment` inside the range, and whether the step divides (d3 keeps steps below 1 as a divisor, so 0.1 stays exact). */
type TickSpec = { first: number; last: number; increment: number; divides: boolean };

/** One past `index` when the multiple it stands for falls before the range, as d3 nudges its first tick in. */
const firstInside = (index: number, at: number, start: number): number => {
    if (at < start) {
        return index + 1;
    }

    return index;
};

/** One before `index` when the multiple it stands for falls past the range, as d3 nudges its last tick in. */
const lastInside = (index: number, at: number, stop: number): number => {
    if (at > stop) {
        return index - 1;
    }

    return index;
};

/** d3-array's `tickSpec`, line for line, so the card's ticks are exactly the page axes' ticks. */
const tickSpec = (start: number, stop: number, count: number): TickSpec => {
    const step = (stop - start) / Math.max(0, count);
    const power = Math.floor(Math.log10(step));
    const factor = tickFactor(step / 10 ** power);
    const spec = tickRun(start, stop, power, factor);

    if (spec.last < spec.first && count >= 0.5 && count < 2) {
        return tickSpec(start, stop, count * 2);
    }

    return spec;
};

/** The run of ticks for a step of `factor` times ten to `power`. */
const tickRun = (start: number, stop: number, power: number, factor: number): TickSpec => {
    if (power < 0) {
        const increment = 10 ** -power / factor;
        const first = Math.round(start * increment);
        const last = Math.round(stop * increment);

        return { first: firstInside(first, first / increment, start), last: lastInside(last, last / increment, stop), increment, divides: true };
    }

    const increment = 10 ** power * factor;
    const first = Math.round(start / increment);
    const last = Math.round(stop / increment);

    return { first: firstInside(first, first * increment, start), last: lastInside(last, last * increment, stop), increment, divides: false };
};

/** The value of the `index`-th tick of a run. */
const tickAt = (spec: TickSpec, index: number): number => {
    if (spec.divides) {
        return (spec.first + index) / spec.increment;
    }

    return (spec.first + index) * spec.increment;
};

/** Round ticks inside [start, stop], about `count` of them: d3-array's `ticks`, which the page's axes use. */
export const linearTicks = (start: number, stop: number, count: number): number[] => {
    if (!(count > 0) || stop < start) {
        return [];
    }

    if (start === stop) {
        return [start];
    }

    const spec = tickSpec(start, stop, count);

    if (spec.last < spec.first) {
        return [];
    }

    return Array.from({ length: spec.last - spec.first + 1 }, (_, index) => tickAt(spec, index));
};

/** Up to `CHART.maxDates` dates evenly spread over a line, like the page's date axes, on whole points only. */
export const evenDates = (count: number): number[] =>
    linearTicks(0, count - 1, Math.min(count, CHART.maxDates)).filter((tick) => Number.isInteger(tick));

/**
 * The days the channel chart dates, as its page axis does: d3's ticks over the
 * axis' `[-0.5, count - 0.5]` domain, keeping the whole days only.
 */
export const comboDates = (count: number): number[] =>
    linearTicks(-0.5, count - 0.5, Math.min(count, CHART.maxDates)).filter((tick) => Number.isInteger(tick));

/** Which of `count` columns get a label when only `fitting` fit: all of them, else every second or third, so none overlap. */
export const fittingColumns = (count: number, fitting: number): number[] => {
    const step = Math.ceil(count / Math.max(fitting, 1));

    return Array.from({ length: count }, (_, index) => index).filter((index) => index % step === 0);
};
