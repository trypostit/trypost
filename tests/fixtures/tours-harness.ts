/**
 * Prints `TOURS` of `resources/js/lib/tours.ts` and its step navigation over
 * the cases in a JSON argument, as JSON, so `ToursTest.php` can check them.
 * Node strips the types, so the source is loaded as-is.
 */
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [sourceFile, casesJson] = process.argv.slice(2);

const { TOURS, firstStep, shownSteps, stepAfter, stepBefore } = await import(
    pathToFileURL(path.resolve(sourceFile)).href
);

const cases = JSON.parse(casesJson) as {
    steps: string[];
    onPage: string[];
    current: string;
}[];

console.log(
    JSON.stringify({
        tours: TOURS,
        cases: cases.map(({ steps, onPage, current }) => {
            const shown = shownSteps(steps, onPage);

            return {
                shown,
                first: firstStep(shown),
                after: stepAfter(shown, current),
                before: stepBefore(shown, current),
            };
        }),
    }),
);
