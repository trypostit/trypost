/**
 * Prints what `lib/noteMentions.ts` reads from each body of a corpus (the
 * mentioned ids and the plain text), so `PostNoteMentionsParityTest.php` can
 * compare them with `App\Support\PostNoteMentions` on the server.
 *
 * Node strips type annotations on import; the resolve hook maps the `@/` alias
 * onto `resources/js/**.ts`.
 */
import fs from 'node:fs';
import { registerHooks } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [resourcesDir, corpusFile] = process.argv.slice(2);

registerHooks({
    resolve: (specifier, context, nextResolve) =>
        specifier.startsWith('@/')
            ? nextResolve(pathToFileURL(path.join(path.resolve(resourcesDir), `${specifier.slice(2)}.ts`)).href, context)
            : nextResolve(specifier, context),
});

const { mentionedPeople, plainNoteBody } = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'lib/noteMentions.ts')).href
);

const corpus = JSON.parse(fs.readFileSync(corpusFile, 'utf8')) as string[];

console.log(
    JSON.stringify(
        corpus.map((body) => ({
            ids: mentionedPeople(body).map((person: { id: string }) => person.id),
            plain: plainNoteBody(body),
        })),
    ),
);
