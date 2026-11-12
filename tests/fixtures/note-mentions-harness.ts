/**
 * Prints what `lib/noteMentions.ts` reads from each body of a corpus, so
 * `PostNoteMentionsParityTest.php` can compare it with `App\Support\PostNoteMentions`
 * on the server: the mentioned ids, whether everyone is mentioned and the plain
 * text (with `all` as the word for everyone). With `round-trip` it prints each
 * body after the composer shows it as `@Name` and stores it again.
 *
 * Node strips type annotations on import; the resolve hook maps the `@/` alias
 * onto `resources/js/**.ts`.
 */
import fs from 'node:fs';
import { registerHooks } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [resourcesDir, corpusFile, mode] = process.argv.slice(2);

registerHooks({
    resolve: (specifier, context, nextResolve) =>
        specifier.startsWith('@/')
            ? nextResolve(pathToFileURL(path.join(path.resolve(resourcesDir), `${specifier.slice(2)}.ts`)).href, context)
            : nextResolve(specifier, context),
});

const { EVERYONE_ID, mentionedPeople, parseNoteBody, plainNoteBody, withMentionMarkup } = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'lib/noteMentions.ts')).href
);

const corpus = JSON.parse(fs.readFileSync(corpusFile, 'utf8')) as string[];

console.log(
    JSON.stringify(
        corpus.map((body) =>
            mode === 'round-trip'
                ? withMentionMarkup(plainNoteBody(body, 'all'), mentionedPeople(body, 'all'))
                : {
                      ids: [
                          ...new Set(
                              mentionedPeople(body, '')
                                  .filter((person: { id: string }) => person.id !== EVERYONE_ID)
                                  .map((person: { id: string }) => person.id),
                          ),
                      ],
                      everyone: parseNoteBody(body).some((segment: { kind: string }) => segment.kind === 'everyone'),
                      plain: plainNoteBody(body, 'all'),
                  },
        ),
    ),
);
