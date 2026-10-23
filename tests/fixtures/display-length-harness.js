/**
 * Runs `displayLength()` from `resources/js/lib/characters.ts` over a corpus for
 * each platform and prints the counts as JSON, so
 * `tests/Feature/Services/Social/DisplayLengthParityTest.php` can compare them
 * with `ContentSanitizer::displayText()`. Node strips the types; the hook only
 * resolves the `@/` alias the source imports with.
 */
import fs from 'node:fs';
import { registerHooks } from 'node:module';
import { pathToFileURL } from 'node:url';

const [jsRoot, corpusFile, platformsFile] = process.argv.slice(2);
const base = pathToFileURL(`${jsRoot}/`).href;

registerHooks({
    resolve: (specifier, context, next) =>
        specifier.startsWith('@/') ? next(new URL(`${specifier.slice(2)}.ts`, base).href, context) : next(specifier, context),
});

const { displayLength } = await import(new URL('lib/characters.ts', base).href);
const corpus = JSON.parse(fs.readFileSync(corpusFile, 'utf8'));
const platforms = JSON.parse(fs.readFileSync(platformsFile, 'utf8'));

console.log(JSON.stringify(Object.fromEntries(platforms.map((platform) => [platform, corpus.map((text) => displayLength(text))]))));
