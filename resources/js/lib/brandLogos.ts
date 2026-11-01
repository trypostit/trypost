import Github from '@thesvg/vue/github';
import Google from '@thesvg/vue/google';
import { h, type Component, type FunctionalComponent } from 'vue';

import type { SocialProvider } from '@/types';

/**
 * A one-color brand logo from @thesvg/vue drawn in the surrounding text
 * color, so it follows the theme. For logos whose paths carry no fill of
 * their own (Unsplash): a fill on a path still wins.
 */
export const inTextColor =
    (logo: Component): FunctionalComponent =>
    (_, { attrs }) =>
        h(logo, { ...attrs, fill: 'currentColor' });

/** The sign-in providers' logos: Google in its colors, GitHub in the text color so it shows in both themes. */
export const SOCIAL_PROVIDER_LOGOS: Record<SocialProvider, { logo: Component; logoClass?: string }> = {
    google: { logo: Google },
    github: { logo: Github, logoClass: '[&_path]:fill-current' },
};
