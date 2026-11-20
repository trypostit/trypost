/**
 * Prints the composer's content types (`types/content-type.ts`): every value,
 * the polls, the post each poll goes back to and the types that carry no
 * media, so `ContentTypeParityTest.php` can compare them with `ContentType`
 * on the server.
 *
 * Node strips type annotations on import; the resolve hook maps the `@/` alias
 * and extension-less specifiers onto `resources/js/**.ts`.
 */
import { registerHooks } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [resourcesDir] = process.argv.slice(2);

registerHooks({
    resolve: (specifier, context, nextResolve) =>
        specifier.startsWith('@/')
            ? nextResolve(pathToFileURL(path.join(path.resolve(resourcesDir), `${specifier.slice(2)}.ts`)).href, context)
            : nextResolve(specifier, context),
});

const { ContentType, MEDIALESS_CONTENT_TYPE_REASONS, POLL_CONTENT_TYPES, POLL_POST_CONTENT_TYPES } = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'types/content-type.ts')).href
);

console.log(
    JSON.stringify({
        values: Object.values(ContentType),
        polls: [...POLL_CONTENT_TYPES],
        poll_posts: POLL_POST_CONTENT_TYPES,
        medialess: Object.keys(MEDIALESS_CONTENT_TYPE_REASONS),
    }),
);
