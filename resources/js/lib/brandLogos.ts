import { h, type Component, type FunctionalComponent } from 'vue';

/**
 * A one-color brand logo from @thesvg/vue drawn in the surrounding text
 * color, so it follows the theme. For logos whose paths carry no fill of
 * their own (Unsplash): a fill on a path still wins.
 */
export const inTextColor =
    (logo: Component): FunctionalComponent =>
    (_, { attrs }) =>
        h(logo, { ...attrs, fill: 'currentColor' });
