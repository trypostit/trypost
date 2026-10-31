const SERIES_COUNT = 10;

export const accountColor = (index: number): string =>
    `var(--series-${(index % SERIES_COUNT) + 1})`;

/** One color per channel, in the order the channels first appear, as the Insights page paints them. */
export const accountColors = (keys: string[]): Record<string, string> =>
    Object.fromEntries([...new Set(keys)].map((key, index) => [key, accountColor(index)]));
