import type { Icon } from '@tabler/icons-vue';
import type { VNode } from 'vue';

/** A Tabler icon's paths on its 24px grid, read from the same component the page renders, so the card draws the same icon. */
export const iconPaths = (icon: Icon): string[] => {
    const svg = icon({}, { attrs: {}, slots: {}, emit: () => {} }) as VNode;
    const children = svg.children as VNode[];

    return children.filter((child) => child.type === 'path').map((child) => child.props?.d);
};
