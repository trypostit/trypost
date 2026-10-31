import type { Point } from './types';

/** d3's `curveMonotoneX`: each segment's handles sit a third along it, and a middle tangent takes at most half the blended slope. */
const MONOTONE = { handle: 3, blend: 0.5 };

/** The sign d3 gives a slope: zero counts as positive. */
const slopeSign = (slope: number): number => {
    if (slope < 0) {
        return -1;
    }

    return 1;
};

/** d3's `slope3`: a middle point's tangent, flat at a peak or a dip, never steeper than its neighbours allow. */
const innerTangent = (before: Point, point: Point, after: Point): number => {
    const left = point.x - before.x;
    const right = after.x - point.x;
    const leftSlope = (point.y - before.y) / left;
    const rightSlope = (after.y - point.y) / right;
    const weighted = (leftSlope * right + rightSlope * left) / (left + right);

    return (slopeSign(leftSlope) + slopeSign(rightSlope)) * Math.min(Math.abs(leftSlope), Math.abs(rightSlope), MONOTONE.blend * Math.abs(weighted)) || 0;
};

/** d3's `slope2`: an end point's tangent, from its segment's slope and the tangent at the segment's other end. */
const endTangent = (from: Point, to: Point, neighbour: number): number => {
    const width = to.x - from.x;

    if (!width) {
        return neighbour;
    }

    return (MONOTONE.handle * (to.y - from.y) / width - neighbour) / 2;
};

/** The line through the points as a monotone cubic, d3's `curveMonotoneX` the page's charts use. */
export const traceMonotone = (context: CanvasRenderingContext2D, points: Point[]): void => {
    context.beginPath();

    if (points.length === 0) {
        return;
    }

    context.moveTo(points[0].x, points[0].y);

    if (points.length < 3) {
        points.slice(1).forEach((point) => context.lineTo(point.x, point.y));

        return;
    }

    const last = points.length - 1;
    const inner = points.map((point, index) => {
        if (index === 0 || index === last) {
            return 0;
        }

        return innerTangent(points[index - 1], point, points[index + 1]);
    });
    const tangents = inner.map((tangent, index) => {
        if (index === 0) {
            return endTangent(points[0], points[1], inner[1]);
        }

        if (index === last) {
            return endTangent(points[last - 1], points[last], inner[last - 1]);
        }

        return tangent;
    });

    points.slice(1).forEach((point, index) => {
        const previous = points[index];
        const third = (point.x - previous.x) / MONOTONE.handle;

        context.bezierCurveTo(
            previous.x + third,
            previous.y + third * tangents[index],
            point.x - third,
            point.y - third * tangents[index + 1],
            point.x,
            point.y,
        );
    });
};
