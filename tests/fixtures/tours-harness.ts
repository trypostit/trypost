/**
 * Runs the step navigation of `resources/js/lib/tours.ts` over the cases in
 * a JSON argument and prints the answers as JSON, so `TourStepsTest.php` can
 * check them. Node strips the types, so the source is loaded as-is.
 */
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [sourceFile, casesJson] = process.argv.slice(2);

const { shownSteps, stepAfter, stepBefore } = await import(
    pathToFileURL(path.resolve(sourceFile)).href
);

const cases = JSON.parse(casesJson) as {
    steps: string[];
    onPage: string[];
    current: string;
}[];

console.log(
    JSON.stringify(
        cases.map(({ steps, onPage, current }) => {
            const shown = shownSteps(steps, onPage);

            return {
                shown,
                after: stepAfter(shown, current),
                before: stepBefore(shown, current),
            };
        }),
    ),
);
