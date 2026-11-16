/**
 * Every tour and its steps, in the order they show. A `TourStep` tour takes
 * each step's copy from `tours.<tour>.steps.<step>.title` and `.description`
 * in `lang/<locale>/tours.php` and is tracked through `app.tours.store`, whose
 * `App\Enums\Tour\Tour` names it. `mcp.prompt` is the card inviting the user
 * to connect an AI (`McpPromptTour`), which keeps its own copy and tracking and
 * only records its "Not now" here.
 */
export const TOURS = {
    'publish.views': ['views'],
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

/** The step after `current` among those shown, or null when it is the last. */
export const stepAfter = (
    shown: readonly string[],
    current: string,
): string | null => {
    const index = shown.indexOf(current);

    if (index === -1) {
        return null;
    }

    if (index === shown.length - 1) {
        return null;
    }

    return shown[index + 1];
};

/** The step before `current` among those shown, or null when it is the first. */
export const stepBefore = (
    shown: readonly string[],
    current: string,
): string | null => {
    const index = shown.indexOf(current);

    if (index <= 0) {
        return null;
    }

    return shown[index - 1];
};
