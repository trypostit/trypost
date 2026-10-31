const SERIES_COUNT = 10;

export const accountColor = (index: number): string =>
    `var(--series-${(index % SERIES_COUNT) + 1})`;
