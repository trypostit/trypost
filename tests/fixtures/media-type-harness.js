/**
 * Runs `resources/js/lib/mediaType.ts` over a corpus of media items and prints
 * `{ type, isMov, isGif }` per entry as JSON, so `MediaTypeParityTest.php` can
 * diff the TypeScript against `App\Enums\Media\Type`, which it mirrors.
 *
 * Node 22 strips type annotations on import, so the source is loaded as-is —
 * no bundler, and nothing in `mediaType.ts` may depend on the DOM.
 */
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [mediaTypeFile, corpusFile] = process.argv.slice(2);

const { classify, isMov, isGif } = await import(pathToFileURL(path.resolve(mediaTypeFile)).href);

const results = JSON.parse(fs.readFileSync(corpusFile, 'utf8')).map((item) => ({
    type: classify(item),
    isMov: isMov(item),
    isGif: isGif(item),
}));

console.log(JSON.stringify(results));
