/**
 * Runs `plainText()` from `resources/js/lib/plainText.ts` over a corpus and
 * prints the results as JSON, so `PlainTextParityTest.php` can compare them
 * with `ContentSanitizer::plainText()`. Node has no DOM, so the textarea that
 * decodes named references answers from a small table of what a browser
 * returns, an unknown name read by its known start included; the browser test
 * `InstagramFirstCommentTest` covers the real one.
 */
import fs from 'node:fs';
import { registerHooks } from 'node:module';
import { pathToFileURL } from 'node:url';

const [jsRoot, corpusFile] = process.argv.slice(2);
const base = pathToFileURL(`${jsRoot}/`).href;

registerHooks({
    resolve: (specifier, context, next) =>
        specifier.startsWith('@/') ? next(new URL(`${specifier.slice(2)}.ts`, base).href, context) : next(specifier, context),
});

const browserNamedReferences: Record<string, string> = {
    '&amp;': '&',
    '&AMP;': '&',
    '&lt;': '<',
    '&gt;': '>',
    '&copy;': '©',
    '&notit;': '¬it;',
    '&copycat;': '©cat;',
};

Object.assign(globalThis, {
    document: {
        createElement: () => {
            const textarea = { value: '' };

            return Object.defineProperty(textarea, 'innerHTML', {
                set: (reference: string) => {
                    textarea.value = browserNamedReferences[reference] ?? reference;
                },
            });
        },
    },
});

const { plainText } = await import(new URL('lib/plainText.ts', base).href);
const corpus: string[] = JSON.parse(fs.readFileSync(corpusFile, 'utf8'));

console.log(JSON.stringify(corpus.map((text) => plainText(text))));
