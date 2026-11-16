/**
 * Every tour and its steps, in the order they show, mirrored by
 * `App\Enums\Tour\Tour`. Each step's copy is
 * `tours.<tour>.steps.<step>.title` and `.description` in
 * `lang/<locale>/tours.php` (`ToursTest` keeps the three in step). A
 * `TourStep` tour is tracked through `app.tours.store`; `mcp.prompt`, the card
 * inviting the user to connect an AI (`McpPromptTour`), keeps its own
 * tracking and only records its "Not now" with the tours.
 */
export const TOURS = {
    'publish.views': ['views'],
    'insights.filters': ['range', 'channels', 'labels'],
    'mcp.prompt': ['card'],
} as const satisfies Record<string, readonly string[]>;

/**
 * The steps of a tour whose control is on the page, in the tour's order: a
 * step whose control is not there (another page, a permission) is left out.
 */
export const shownSteps = (
    steps: readonly string[],
    onPage: readonly string[],
): string[] => steps.filter((step) => onPage.includes(step));

/**
 * The step a tour opens on: the first of those shown, or null when none of
 * its controls is on the page. This file imports nothing, `@/lib/helpers`
 * included, so the Node harness of `ToursTest` can load it as it is.
 */
export const firstStep = (shown: readonly string[]): string | null => {
    if (shown.length === 0) {
        return null;
    }

    return shown[0];
};

/** The last of the steps shown, or null when none is. */
export const lastStep = (shown: readonly string[]): string | null =>
    firstStep([...shown].reverse());

/** The step after `current` among those shown: the first of those after it, or null when it is the last. */
export const stepAfter = (
    shown: readonly string[],
    current: string,
): string | null => {
    if (!shown.includes(current)) {
        return null;
    }

    return firstStep(shown.slice(shown.indexOf(current) + 1));
};

/** The step before `current` among those shown: the last of those before it, or null when it is the first. */
export const stepBefore = (
    shown: readonly string[],
    current: string,
): string | null => {
    if (!shown.includes(current)) {
        return null;
    }

    return lastStep(shown.slice(0, shown.indexOf(current)));
};
