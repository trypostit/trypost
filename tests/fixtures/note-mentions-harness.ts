/**
 * Prints what `lib/noteMentions.ts` reads from each entry of a corpus, so
 * `PostNoteMentionsParityTest.php` can compare it with `App\Support\PostNoteMentions`
 * on the server:
 * - `read` (default): the mentioned ids and whether everyone is mentioned;
 * - `round-trip`: each body after the note editor opens it and stores it again,
 *   read back through TipTap's own `generateText` with the editor's plugins.
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

const { generateText } = await import('@tiptap/core');
const { noteBodyToDoc, parseNoteBody } = await load('lib/noteMentions.ts');
const { plainTextExtensions } = await load('components/tiptap/editor.ts');
const { NoteBody } = await load('components/tiptap/plugins/noteBody.ts');

const noteExtensions = [...plainTextExtensions(() => ''), NoteBody];

type Segment = { kind: 'text'; text: string } | { kind: 'mention'; userId: string } | { kind: 'everyone' };

const read = (body: string) => {
    const segments = parseNoteBody(body) as Segment[];

    return {
        ids: [
            ...new Set(
                segments.flatMap((segment) =>
                    segment.kind === 'mention' ? [segment.userId] : [],
                ),
            ),
        ],
        everyone: segments.some((segment) => segment.kind === 'everyone'),
    };
};

const corpus = JSON.parse(fs.readFileSync(corpusFile, 'utf8')) as string[];

const answers = {
    read,
    'round-trip': (body: string) => generateText(noteBodyToDoc(body, () => 'label'), noteExtensions, { blockSeparator: '\n' }),
}[mode];

console.log(JSON.stringify(corpus.map((entry) => answers?.(entry))));
