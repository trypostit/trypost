/** How close a label may sit to its placed neighbour, `step` away from it; `edge` when it has none. */
const fromNeighbour = (placed: number[], neighbour: number | undefined, step: number, edge: number): number => {
    if (neighbour === undefined) {
        return edge;
    }

    return placed[neighbour] + step;
};

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
        const lowest = fromNeighbour(placed, above, step, top + step / 2);

        placed[index] = Math.max(targets[index], lowest);
    });

    for (let position = order.length - 1; position >= 0; position -= 1) {
        const below = order[position + 1];
        const highest = fromNeighbour(placed, below, -step, bottom - step / 2);

        placed[order[position]] = Math.min(placed[order[position]], highest);
    }

    return placed;
};
