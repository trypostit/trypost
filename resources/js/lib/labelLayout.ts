/**
 * Where a column of labels goes when each wants to sit at its own height
 * (`targets`, top to bottom in any order) but none may come closer than `gap`
 * to the next: labels that collide are pushed down, then the whole run is
 * lifted back above `bottom`. Returns the heights in the order of `targets`,
 * shrinking the gap when there are too many labels for the room.
 */
export const spreadLabels = (
    targets: number[],
    { gap, top, bottom }: { gap: number; top: number; bottom: number },
): number[] => {
    const order = targets.map((_, index) => index).sort((a, b) => targets[a] - targets[b]);
    const step = Math.min(gap, (bottom - top) / Math.max(targets.length, 1));
    const placed = [...targets];

    order.forEach((index, position) => {
        const above = order[position - 1];
        const lowest = above === undefined ? top + step / 2 : placed[above] + step;

        placed[index] = Math.max(targets[index], lowest);
    });

    for (let position = order.length - 1; position >= 0; position -= 1) {
        const below = order[position + 1];
        const highest = below === undefined ? bottom - step / 2 : placed[below] - step;

        placed[order[position]] = Math.min(placed[order[position]], highest);
    }

    return placed;
};
