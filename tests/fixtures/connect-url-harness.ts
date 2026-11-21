/**
 * Prints the reconnect URL `connectUrl()` (`lib/connectRoutes.ts`)
 * builds for each platform given as an argument, so `ConnectUrlTest.php` can
 * check it against the server's connect routes. A LinkedIn Page reconnects
 * through LinkedIn; a network without a connect route (Telegram) has none.
 *
 * Node strips type annotations on import; the resolve hook maps the `@/` alias
 * and extension-less specifiers onto `resources/js/**.ts`.
 */
import { existsSync } from 'node:fs';
import { registerHooks } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [resourcesDir, ...platforms] = process.argv.slice(2);

/** A module path as a file: `name.ts`, or `name/index.ts` for a folder of routes. */
const moduleFile = (base: string): string => (existsSync(`${base}.ts`) ? `${base}.ts` : path.join(base, 'index.ts'));

registerHooks({
    resolve: (specifier, context, nextResolve) => {
        if (specifier.startsWith('@/')) {
            return nextResolve(pathToFileURL(moduleFile(path.join(path.resolve(resourcesDir), specifier.slice(2)))).href, context);
        }

        if (specifier.startsWith('.') && !path.extname(specifier) && context.parentURL?.includes(path.resolve(resourcesDir))) {
            return nextResolve(pathToFileURL(moduleFile(path.resolve(path.dirname(new URL(context.parentURL).pathname), specifier))).href, context);
        }

        return nextResolve(specifier, context);
    },
});

Object.assign(globalThis, { window: { location: { pathname: '/schedule' } } });

const { connectUrl } = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'lib/connectRoutes.ts')).href
);

console.log(JSON.stringify(Object.fromEntries(platforms.map((platform) => [platform, connectUrl(platform, 'channel-1') ?? null]))));
