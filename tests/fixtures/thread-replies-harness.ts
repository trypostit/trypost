/**
 * Prints what the composer's thread helpers (`lib/threadReplies.ts`) answer for
 * each platform given as an argument, so `ThreadRepliesParityTest.php` can
 * compare them with `ThreadReplies` on the server.
 *
 * Node strips type annotations on import; the resolve hook maps the `@/` alias
 * and extension-less specifiers onto `resources/js/**.ts`.
 */
import { registerHooks } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [resourcesDir, ...platforms] = process.argv.slice(2);

registerHooks({
    resolve: (specifier, context, nextResolve) =>
        specifier.startsWith('@/')
            ? nextResolve(pathToFileURL(path.join(path.resolve(resourcesDir), `${specifier.slice(2)}.ts`)).href, context)
            : nextResolve(specifier, context),
});

const { replyEmptyKey, replyTooLongKey, startThreadKey, threadMaxReplies } = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'lib/threadReplies.ts')).href
);
const { THREAD_REPLY_CONTENT_TYPES } = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'types/network-options.ts')).href
);

const results = Object.fromEntries(
    platforms.map((platform) => [
        platform,
        {
            reply_content_type: THREAD_REPLY_CONTENT_TYPES[platform] ?? null,
            max_replies: threadMaxReplies(platform),
            empty_key: replyEmptyKey(platform),
            too_long_key: replyTooLongKey(platform),
            start_key: startThreadKey(platform),
        },
    ]),
);

console.log(JSON.stringify(results));
