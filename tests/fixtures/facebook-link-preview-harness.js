/**
 * Runs `resources/js/lib/facebookLinkPreview.ts` over a corpus and prints the
 * results as JSON, so the PHP parity test can diff it against
 * `App\Support\FacebookLinkPreview`.
 */
import fs from 'node:fs';
import { pathToFileURL } from 'node:url';

const [modulePath, corpusPath] = process.argv.slice(2);
const { facebookLinkPreviewUrl } = await import(pathToFileURL(modulePath).href);
const corpus = JSON.parse(fs.readFileSync(corpusPath, 'utf8'));

console.log(JSON.stringify(corpus.map((entry) => facebookLinkPreviewUrl(entry))));
