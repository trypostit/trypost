/**
 * Opens each note, given as the parts the server sends, in the note editor's
 * document and prints the body TipTap stores again (its own `generateText`
 * with the editor's plugins), so `PostNoteMentionsTest.php` can check that a
 * note survives an edit unchanged.
 *
 * Node strips type annotations on import; the resolve hook maps the `@/` alias
 * onto `resources/js/**.ts`.
 */
import fs from 'node:fs';
import { registerHooks } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [resourcesDir, partsFile] = process.argv.slice(2);

registerHooks({
    resolve: (specifier, context, nextResolve) =>
        specifier.startsWith('@/')
            ? nextResolve(pathToFileURL(path.join(path.resolve(resourcesDir), `${specifier.slice(2)}.ts`)).href, context)
            : nextResolve(specifier, context),
});

const load = async (file: string) => import(pathToFileURL(path.join(path.resolve(resourcesDir), file)).href);

const { generateText } = await import('@tiptap/core');
const { noteDoc } = await load('lib/noteMentions.ts');
const { plainTextExtensions } = await load('components/tiptap/editor.ts');
const { NoteBody } = await load('components/tiptap/plugins/noteBody.ts');

const extensions = [...plainTextExtensions(() => ''), NoteBody];
const notes = JSON.parse(fs.readFileSync(partsFile, 'utf8')) as unknown[][];

console.log(JSON.stringify(notes.map((parts) => generateText(noteDoc(parts, { everyone: 'all', unknown: 'unknown' }), extensions, { blockSeparator: '\n' }))));
