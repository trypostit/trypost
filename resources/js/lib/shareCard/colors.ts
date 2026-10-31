import { CHANGE_TOKENS } from './theme';

/** Every color resolved so far: the light palette never changes while the page is open. */
const resolved = new Map<string, string>();

/**
 * The value any CSS color stands for in the light theme (`var(--series-3)`,
 * `oklch(…)`, a name), as the browser computes it, even on a dark page: a
 * canvas cannot read CSS variables, and the card is always light.
 */
const resolveLightCssColor = (color: string): string => {
    const probe = document.createElement('span');

    probe.dataset.lightTheme = '';
    probe.style.color = color;
    document.body.append(probe);

    const value = getComputedStyle(probe).color;

    probe.remove();

    return value;
};

/** A color as the canvas can draw it, resolved once per page. */
export const lightColor = (color: string): string => {
    const known = resolved.get(color) ?? resolveLightCssColor(color);

    resolved.set(color, known);

    return known;
};

/** The app's text, as `--foreground`. */
export const ink = (): string => lightColor('var(--foreground)');

/** The app's secondary text, as `--muted-foreground`. */
export const muted = (): string => lightColor('var(--muted-foreground)');

/** The app's card surface, as `--card`: the panel, the logo's disc, the avatars' rings. */
export const surface = (): string => lightColor('var(--card)');

/** The charts' dashed grid, as `--border`. */
export const gridColor = (): string => lightColor('var(--border)');

/** A change's tone, as `MetricChange` draws it. */
export const changeColor = (direction: keyof typeof CHANGE_TOKENS): string => lightColor(CHANGE_TOKENS[direction]);
