/**
 * Runs `plainText()` from `resources/js/lib/plainText.ts` over a corpus and
 * prints the results as JSON, so `PlainTextParityTest.php` can compare them
 * with `ContentSanitizer::plainText()`. Node has no DOM, so the textarea
 * answers as a browser does: named references from a small table (an unknown
 * name read by its known start included), numeric ones by the HTML spec rules
 * (null, surrogates and out of range as U+FFFD, `&#128;` as `€`). The browser
 * test `InstagramFirstCommentTest` covers the real one.
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

const NUMERIC_REFERENCE = /^&#(?:x([0-9a-f]+)|([0-9]+));$/i;
const WINDOWS_1252: Record<number, string> = { 0x80: '€' };

const SURROGATES: readonly [number, number] = [0xd800, 0xdfff];

const browserNumericReference = (codePoint: number): string => {
    if (codePoint === 0 || codePoint > 0x10ffff) {
        return '\uFFFD';
    }

    if (codePoint >= SURROGATES[0] && codePoint <= SURROGATES[1]) {
        return '\uFFFD';
    }

    return WINDOWS_1252[codePoint] ?? String.fromCodePoint(codePoint);
};

const browserDecode = (reference: string): string => {
    const numeric = reference.match(NUMERIC_REFERENCE);
    if (numeric?.[1] !== undefined) {
        return browserNumericReference(parseInt(numeric[1], 16));
    }

    if (numeric?.[2] !== undefined) {
        return browserNumericReference(parseInt(numeric[2], 10));
    }

    return browserNamedReferences[reference] ?? reference;
};

Object.assign(globalThis, {
    document: {
        createElement: () => {
            const textarea = { value: '' };

            return Object.defineProperty(textarea, 'innerHTML', {
                set: (reference: string) => {
                    textarea.value = browserDecode(reference);
                },
            });
        },
    },
});

const { plainText } = await import(new URL('lib/plainText.ts', base).href);
const corpus: string[] = JSON.parse(fs.readFileSync(corpusFile, 'utf8'));

console.log(JSON.stringify(corpus.map((text) => plainText(text))));
