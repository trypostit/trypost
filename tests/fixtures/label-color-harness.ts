/**
 * Runs `labelTextColor()` of `resources/js/lib/labelColor.ts` over a list of
 * colors and prints the answers as JSON, so `LabelColorTest.php` can check
 * them. Node strips the types, so the source is loaded as-is.
 */
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [sourceFile, ...colors] = process.argv.slice(2);

const { labelTextColor } = await import(pathToFileURL(path.resolve(sourceFile)).href);

console.log(JSON.stringify(colors.map((color) => labelTextColor(color) ?? null)));
