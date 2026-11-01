/** The light tints of the "What's New" covers, with the strong color the data is drawn in. */
export const SHARE_TINTS = [
    { key: 'violet', ground: '#d2c7fd', strong: '#6d28d9' },
    { key: 'purple', ground: '#ead9fb', strong: '#7e22ce' },
    { key: 'fuchsia', ground: '#f6dcfb', strong: '#a21caf' },
    { key: 'pink', ground: '#fce1ef', strong: '#db2777' },
    { key: 'red', ground: '#fde0e0', strong: '#dc2626' },
    { key: 'peach', ground: '#ffdbd6', strong: '#ea580c' },
    { key: 'orange', ground: '#ffe4c8', strong: '#c2410c' },
    { key: 'yellow', ground: '#fdefc3', strong: '#a16207' },
    { key: 'lime', ground: '#eaf7c8', strong: '#4d7c0f' },
    { key: 'green', ground: '#d9f1d1', strong: '#16a34a' },
    { key: 'teal', ground: '#ccf5ea', strong: '#0f766e' },
    { key: 'cyan', ground: '#cff4fc', strong: '#0e7490' },
    { key: 'blue', ground: '#def0ff', strong: '#2563eb' },
    { key: 'indigo', ground: '#dfe3fd', strong: '#4338ca' },
    { key: 'slate', ground: '#e3e8ef', strong: '#334155' },
    { key: 'neutral', ground: '#f4f1ec', strong: '#292928' },
] as const;

/** One of the card's tints. */
export type ShareTint = (typeof SHARE_TINTS)[number];

/** The card's size, 16:9, in the units every painter draws in. */
export const SHARE_CARD_SIZE = { width: 1200, height: 675 };

/** How many pixels the exported file draws per card unit, for sharp text on high-density screens. */
export const SHARE_EXPORT_SCALE = 2;

/** The ink border of the panel and the Summary's rules, like a cover's settings panel. */
export const BORDER = 2.5;

/** The app's body face, for labels and ticks. */
const SANS = "Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif";

/** The app's heading face (`--font-heading`), for names and numbers. */
const HEADING = "Outfit, Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif";

/** A body font at `weight` and `size`. */
export const sans = (weight: number, size: number): string => `${weight} ${size}px ${SANS}`;

/** A heading font at `weight` and `size`. */
export const heading = (weight: number, size: number): string => `${weight} ${size}px ${HEADING}`;

/**
 * The app's charts (`ChartContainer`, `ChannelMetricsChart`) at twice their
 * size, since the card is the page at 2x: a dashed `--border` grid, muted
 * ticks, bars without outline rounded at the data end, a 2px monotone line
 * with a dot on each point.
 */
export const CHART = {
    gridDash: [6, 6],
    gridWidth: 2,
    tickFont: sans(500, 22),
    barMaxWidth: 32,
    barMinWidth: 2,
    barPadding: 0.35,
    /** The page's bars round their data end 4px on 20px bars: the radius keeps that share of a bar's thickness. */
    barRoundness: 4 / 20,
    lineWidth: 4,
    dotRadius: 4,
    /** The page's value axes ask d3 for five ticks, its date axes for six. */
    valueTicks: 5,
    maxDates: 6,
};

/** `MetricChange`'s tones, as the app's text tokens. */
export const CHANGE_TOKENS = { up: 'var(--success-text)', down: 'var(--destructive-text)', flat: 'var(--muted-foreground)' } as const;

/** The white surface, inset from the tint like a cover's panel, over the signature's band. */
export const PANEL = { x: 56, y: 48, radius: 24, padding: 48, band: 92 };

/** The name and what-and-when lines at the panel's top, as baselines from where they start. */
export const IDENTITY = { top: 40, name: 34, nameSize: 36, detail: 76, detailSize: 22 };

/** The chart card's headline: the figure at the top right and its change under it. */
export const HEADLINE = { reserve: 380, figure: 100, figureSize: 68, change: 116, changeSize: 26 };

/** The plot under the headline, with a gutter for the value ticks and room for the dates under it. */
export const PLOT = { top: 212, bottom: 76, gutter: 72, tickGap: 18, dates: 40, dateGap: 24 };
