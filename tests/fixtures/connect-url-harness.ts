/**
 * Prints the reconnect URL `connectUrl()` (`lib/app/connectRoutes.ts`)
 * builds for each platform given as an argument, so `ConnectUrlTest.php` can
 * check it against the server's connect routes. The connect routes are built
 * by `connectRouteModule()` below, so the harness needs no frontend build. A LinkedIn Page reconnects
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

/**
 * A connect route as Wayfinder generates it (`resources/js/routes/app/social/<name>`),
 * built here because the generated routes exist only after a frontend build,
 * which the backend test run does not make. Its URL follows the server's
 * `/connect/<name>` routes, which `ConnectUrlTest.php` compares it with.
 */
const connectRouteModule = (name: string): string =>
    `data:text/javascript,${encodeURIComponent(`export const connect = { url: (options) => '/connect/${name}' + (options?.query ? '?' + new URLSearchParams(options.query).toString() : '') };`)}`;

registerHooks({
    resolve: (specifier, context, nextResolve) => {
        if (specifier.startsWith('@/routes/app/social/')) {
            return { url: connectRouteModule(specifier.slice('@/routes/app/social/'.length)), shortCircuit: true };
        }

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
    pathToFileURL(path.join(path.resolve(resourcesDir), 'lib/app/connectRoutes.ts')).href
);

console.log(JSON.stringify(Object.fromEntries(platforms.map((platform) => [platform, connectUrl(platform, 'channel-1') ?? null]))));
