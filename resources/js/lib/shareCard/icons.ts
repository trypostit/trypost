import { type Component, h, render } from 'vue';

const paths = new Map<Component, string[]>();

/** A Tabler icon's paths on its 24px grid, read from the component the page renders, so the card draws the same icon. */
export const iconPaths = (icon: Component): string[] => {
    const cached = paths.get(icon);

    if (cached) {
        return cached;
    }

    const element = document.createElement('div');

    render(h(icon), element);

    const drawn = Array.from(element.querySelectorAll('path'), (path) => path.getAttribute('d') ?? '');

    render(null, element);
    paths.set(icon, drawn);

    return drawn;
};
