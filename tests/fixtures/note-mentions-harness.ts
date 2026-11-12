/**
 * Prints what `lib/noteMentions.ts` reads from each entry of a corpus, so
 * `PostNoteMentionsParityTest.php` can compare it with `App\Support\PostNoteMentions`
 * on the server:
 * - `read` (default): the mentioned ids, whether everyone is mentioned and the
 *   plain text (with `all` as the word for everyone);
 * - `round-trip`: each body after the note editor opens it and stores it again;
 * - `names`: each name as it goes inside the markup.
 *
 * Node strips type annotations on import; the resolve hook maps the `@/` alias
 * onto `resources/js/**.ts`.
 */
import fs from 'node:fs';
import { registerHooks } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [resourcesDir, corpusFile, mode = 'read'] = process.argv.slice(2);

registerHooks({
    resolve: (specifier, context, nextResolve) =>
        specifier.startsWith('@/')
            ? nextResolve(pathToFileURL(path.join(path.resolve(resourcesDir), `${specifier.slice(2)}.ts`)).href, context)
            : nextResolve(specifier, context),
});

const load = async (file: string) => import(pathToFileURL(path.join(path.resolve(resourcesDir), file)).href);

const { docToNoteBody, markupName, noteBodyToDoc, parseNoteBody } = await load('lib/noteMentions.ts');
const { isUuid } = await load('lib/helpers.ts');

type Segment = { kind: 'text'; text: string } | { kind: 'mention'; name: string; userId: string } | { kind: 'everyone' };

const read = (body: string) => {
    const segments = parseNoteBody(body) as Segment[];

    return {
        ids: [
            ...new Set(
                segments.flatMap((segment) =>
                    segment.kind === 'mention' && isUuid(segment.userId) ? [segment.userId.toLowerCase()] : [],
                ),
            ),
        ],
        everyone: segments.some((segment) => segment.kind === 'everyone'),
        plain: segments
            .map((segment) => (segment.kind === 'text' ? segment.text : `@${segment.kind === 'mention' ? segment.name : 'all'}`))
            .join(''),
    };
};

const corpus = JSON.parse(fs.readFileSync(corpusFile, 'utf8')) as string[];

const answers = {
    read,
    'round-trip': (body: string) => docToNoteBody(noteBodyToDoc(body, 'all')),
    names: (name: string) => markupName(name),
}[mode];

console.log(JSON.stringify(corpus.map((entry) => answers?.(entry))));
