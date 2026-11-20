/**
 * Runs the composer's content type choice for a destination with no type picked
 * (`resolvedDestination()` in `usePostComposition.ts`: the media-derived type,
 * else the platform's first type) over every case and prints the chosen types as
 * JSON, so `DerivedContentTypeParityTest.php` can assert `ContentType::forMedia()`
 * picks the same.
 *
 * Node strips type annotations on import; the resolve hook maps the `@/` alias
 * and extension-less specifiers onto `resources/js/**.ts`.
 */
import fs from 'node:fs';
import { registerHooks } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [resourcesDir, inputFile] = process.argv.slice(2);

registerHooks({
    resolve: (specifier, context, nextResolve) =>
        specifier.startsWith('@/')
            ? nextResolve(pathToFileURL(path.join(path.resolve(resourcesDir), `${specifier.slice(2)}.ts`)).href, context)
            : nextResolve(specifier, context),
});

const { derivedContentTypeFor } = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'lib/derivedContentType.ts')).href
);
const { getContentTypeOptions } = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'composables/usePlatformLogo.ts')).href
);

type Case = { platform: string; media: unknown[] };

const cases: Record<string, Case> = JSON.parse(fs.readFileSync(inputFile, 'utf8'));

const results = Object.fromEntries(
    Object.entries(cases).map(([key, { platform, media }]) => [
        key,
        derivedContentTypeFor(platform, media) ?? getContentTypeOptions(platform)[0]?.value ?? null,
    ]),
);

console.log(JSON.stringify(results));
