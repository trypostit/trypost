/**
 * Runs the share card's pure arithmetic (`resources/js/lib/shareCard/scale.ts`,
 * `curve.ts` and `lib/labelLayout.ts`) over fixed inputs and prints the results
 * as JSON, so `ShareCardArithmeticTest.php` can hold them to d3's own output
 * and to hand-worked layouts.
 *
 * Node strips type annotations on import; the resolve hook maps the `@/` alias
 * onto `resources/js/**.ts`.
 */
import { registerHooks } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [resourcesDir] = process.argv.slice(2);
const root = path.resolve(resourcesDir);

registerHooks({
    resolve: (specifier, context, nextResolve) => {
        if (specifier.startsWith('@/')) {
            return nextResolve(pathToFileURL(path.join(root, `${specifier.slice(2)}.ts`)).href, context);
        }

        return nextResolve(specifier, context);
    },
});

type Op = [string, ...number[]];

const load = (file: string) => import(pathToFileURL(path.join(root, file)).href);
const { comboDates, evenDates, fittingColumns, linearTicks, quantizeLevel, valueAxis } = await load('lib/shareCard/scale.ts');
const { scaleQuantize } = await import('d3-scale');
const { traceMonotone } = await load('lib/shareCard/curve.ts');
const { spreadLabels } = await load('lib/labelLayout.ts');

/** A canvas context that records the path it is asked to draw. */
const recorder = () => {
    const ops: Op[] = [];
    const round = (values: number[]): number[] => values.map((value) => Number(value.toFixed(6)));

    return {
        ops,
        beginPath: () => {},
        moveTo: (...values: number[]) => ops.push(['M', ...round(values)]),
        lineTo: (...values: number[]) => ops.push(['L', ...round(values)]),
        bezierCurveTo: (...values: number[]) => ops.push(['C', ...round(values)]),
    };
};

const curve = (points: [number, number][]): Op[] => {
    const context = recorder();

    traceMonotone(context, points.map(([x, y]) => ({ x, y })));

    return context.ops;
};

const axis = valueAxis([-26, 30], 5);

/** Every day's step from one post to the busiest day, by the card and by d3's scale on the page's domain. */
const levels = (maxCount: number) => {
    const counts = Array.from({ length: Math.max(maxCount, 1) }, (_, index) => index + 1);
    const page = scaleQuantize<number>().domain([1, Math.max(maxCount, 1)]).range([0, 1, 2, 3]);

    return { card: counts.map((count) => quantizeLevel(count, maxCount, 4)), page: counts.map((count) => page(count)) };
};

console.log(
    JSON.stringify({
        ticks: {
            days: linearTicks(0, 29, 6),
            growth: linearTicks(-26, 30, 5),
            closeFollowers: linearTicks(9990, 10010, 5),
            fractions: linearTicks(0, 0.3, 5),
            halfSteps: linearTicks(1000, 1002, 5),
        },
        dates: { combo9: comboDates(9), combo19: comboDates(19), even2: evenDates(2), even30: evenDates(30) },
        columns: { crowded: fittingColumns(13, 5), roomy: fittingColumns(4, 10) },
        axis: { ticks: axis.ticks, zero: axis.zero },
        curve: {
            spike: curve([[0, 10], [10, 10], [20, 10], [30, 80], [40, 20]]),
            rising: curve([[0, 0], [10, 1], [20, 10], [30, 30]]),
            two: curve([[0, 0], [10, 5]]),
        },
        levels: { one: levels(1), four: levels(4), ten: levels(10), odd: levels(37), busy: levels(250) },
        labels: {
            apart: spreadLabels([100, 102, 300], { gap: 24, top: 0, bottom: 400 }),
            atTheBottom: spreadLabels([390, 395], { gap: 24, top: 0, bottom: 400 }),
        },
    }),
);
