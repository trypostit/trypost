/**
 * The paths of one column of a funnel ribbon: an S-curve that narrows from
 * the step's half-height to the next step's across nearly the whole column,
 * around a vertical center. Each step's band is its own column.
 */
export interface FunnelColumnPaths {
    /** The closed fill of the step's band. */
    path: string;
    /** The open top and bottom edges, drawn under the fill as a soft halo. */
    edgePath: string;
}

const round = (value: number): number => Math.round(value * 100) / 100;

const lerp = (from: number, to: number, t: number): number => from + (to - from) * t;

/** Where along the column the curve's points and handles sit, as fractions of its width. */
const TAPER = {
    lead: 0.055,
    c1a: 0.11,
    c1b: 0.22,
    p1: 0.332,
    y1: 0.167,
    c2a: 0.443,
    c2b: 0.557,
    p2: 0.668,
    y2: 0.833,
    c3a: 0.78,
    c3b: 0.89,
    end: 0.945,
} as const;

export const buildFunnelColumnPaths = (
    x0: number,
    width: number,
    centerY: number,
    halfLeft: number,
    halfRight: number,
): FunnelColumnPaths => {
    const x = (fraction: number): number => round(x0 + fraction * width);
    const top = (t: number): number => round(lerp(centerY - halfLeft, centerY - halfRight, t));
    const bottom = (t: number): number => round(lerp(centerY + halfLeft, centerY + halfRight, t));

    const topEdge = [
        `M ${x(0)} ${top(0)}`,
        `L ${x(TAPER.lead)} ${top(0)}`,
        `C ${x(TAPER.c1a)} ${top(0)} ${x(TAPER.c1b)} ${top(0)} ${x(TAPER.p1)} ${top(TAPER.y1)}`,
        `C ${x(TAPER.c2a)} ${top(0.333)} ${x(TAPER.c2b)} ${top(0.667)} ${x(TAPER.p2)} ${top(TAPER.y2)}`,
        `C ${x(TAPER.c3a)} ${top(1)} ${x(TAPER.c3b)} ${top(1)} ${x(TAPER.end)} ${top(1)}`,
        `L ${x(1)} ${top(1)}`,
    ];

    const bottomCurve = [
        `L ${x(TAPER.end)} ${bottom(1)}`,
        `C ${x(TAPER.c3b)} ${bottom(1)} ${x(TAPER.c3a)} ${bottom(1)} ${x(TAPER.p2)} ${bottom(TAPER.y2)}`,
        `C ${x(TAPER.c2b)} ${bottom(0.667)} ${x(TAPER.c2a)} ${bottom(0.333)} ${x(TAPER.p1)} ${bottom(TAPER.y1)}`,
        `C ${x(TAPER.c1b)} ${bottom(0)} ${x(TAPER.c1a)} ${bottom(0)} ${x(TAPER.lead)} ${bottom(0)}`,
        `L ${x(0)} ${bottom(0)}`,
    ];

    return {
        path: [...topEdge, `L ${x(1)} ${bottom(1)}`, ...bottomCurve, 'Z'].join(' '),
        edgePath: [...topEdge, `M ${x(1)} ${bottom(1)}`, ...bottomCurve].join(' '),
    };
};

/** The funnel's shades, lightest first: each step a little darker than the one before. */
const SHADES = [
    'color-mix(in srgb, var(--chart-4) 45%, var(--card))',
    'var(--chart-4)',
    'color-mix(in srgb, var(--chart-4) 50%, var(--chart-3))',
    'var(--chart-3)',
    'var(--chart-2)',
    'color-mix(in srgb, var(--chart-2) 50%, var(--chart-1))',
    'var(--chart-1)',
] as const;

export const funnelShade = (index: number, count: number): string => {
    if (count <= 1) {
        return SHADES[SHADES.length - 1];
    }

    const slot = Math.round((index / (count - 1)) * (SHADES.length - 1));

    return SHADES[slot] ?? SHADES[0];
};
