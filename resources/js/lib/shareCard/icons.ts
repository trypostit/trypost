import type { Icon } from '@tabler/icons-vue';
import type { VNode } from 'vue';

import type { Point } from '@/lib/shareCard/types';

/** A Tabler icon's paths on its 24px grid, read from the same component the page renders, so the card draws the same icon. */
export const iconPaths = (icon: Icon): string[] => {
    const svg = icon({}, { attrs: {}, slots: {}, emit: () => {} }) as VNode;
    const children = svg.children as VNode[];

    return children.filter((child) => child.type === 'path').map((child) => child.props?.d);
};

/** Fills a Tabler icon at `at` (its top left corner), `size` pixels square, in a color the canvas can draw. */
export const paintIcon = (context: CanvasRenderingContext2D, icon: Icon, color: string, at: Point, size: number): void => {
    context.save();
    context.translate(at.x, at.y);
    context.scale(size / 24, size / 24);
    context.fillStyle = color;
    iconPaths(icon).forEach((path) => context.fill(new Path2D(path)));
    context.restore();
};
