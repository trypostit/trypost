/**
 * Runs `blank()` and `filled()` from `resources/js/lib/helpers.ts` over a corpus
 * of JSON values and prints `{ blank, filled }` per entry as JSON, so
 * `HelpersParityTest.php` can diff them against Laravel's helpers.
 *
 * Node strips type annotations, so the source is loaded as-is.
 */
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [helpersFile, corpusFile] = process.argv.slice(2);

const { blank, filled } = await import(pathToFileURL(path.resolve(helpersFile)).href);

const results = (JSON.parse(fs.readFileSync(corpusFile, 'utf8')) as unknown[]).map((value) => ({
    blank: blank(value),
    filled: filled(value),
}));

console.log(JSON.stringify(results));
