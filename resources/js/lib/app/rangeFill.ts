/**
 * The filled stretch of an `.editor-range` slider: from `origin` (the start,
 * or the centre of a slider that goes both ways) to the value. Positions follow
 * the thumb's centre, which never reaches the track's last 7px on either side;
 * a fill from the start begins at the track's edge.
 */
export const rangeFill = (
    value: number,
    min: number,
    max: number,
    origin: number = min,
): Record<string, string> => {
    const position = (point: number): string =>
        `calc(7px + (100% - 14px) * ${(Math.min(Math.max(point, min), max) - min) / (max - min || 1)})`;
    const [from, to] = value < origin ? [value, origin] : [origin, value];

    return {
        '--range-from': from <= min ? '0%' : position(from),
        '--range-to': position(to),
    };
};
