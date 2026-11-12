/**
 * Runs the helpers of `resources/js/lib/helpers.ts` over a corpus of JSON values
 * (and `clamp()` over a few ranges) and prints the answers as JSON, so
 * `HelpersParityTest.php` can diff them against Laravel's and PHP's own.
 *
 * Node strips type annotations, so the source is loaded as-is.
 */
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [helpersFile, corpusFile] = process.argv.slice(2);

const { blank, filled, isEmpty, isString, isNumber, isUuid, transform, clamp } = await import(pathToFileURL(path.resolve(helpersFile)).href);

const values = (JSON.parse(fs.readFileSync(corpusFile, 'utf8')) as unknown[]).map((value) => ({
    blank: blank(value),
    filled: filled(value),
    isString: isString(value),
    isNumber: isNumber(value),
    transform: transform(value, () => 'changed', 'fallback'),
}));

const clamped = [[5, 1, 10], [-3, 1, 10], [42, 1, 10], [1, 1, 10], [10, 1, 10], [2.5, 0, 1]].map(([value, min, max]) => clamp(value, min, max));

const empties = ['', ' ', 'a', '0', [], [null], ['']].map((value) => isEmpty(value));

const uuids = [
    '0198d0aa-1b2c-7d3e-8f40-5a6b7c8d9e0f',
    '0198D0AA-1B2C-7D3E-8F40-5A6B7C8D9E0F',
    '00000000-0000-0000-0000-000000000000',
    '0198d0aa-1b2c-7d3e-8f40-5a6b7c8d9e0',
    '0198d0aa-1b2c-7d3e-8f40-5a6b7c8d9e0fa',
    '0198d0aa1b2c7d3e8f405a6b7c8d9e0f',
    '0198d0aa-1b2c-7d3e-8f40-zzzzzzzzzzzz',
    '------------------------------------',
    ' 0198d0aa-1b2c-7d3e-8f40-5a6b7c8d9e0f',
    '0198d0aa-1b2c-7d3e-8f40-5a6b7c8d9e0f\n',
    '',
    null,
    42,
].map((value) => isUuid(value));

console.log(JSON.stringify({ values, clamped, empties, uuids }));
