/**
 * Runs `toastLinkOptions()` of `resources/js/lib/toastLink.ts` with and
 * without a link, and merges them the way vue-sonner updates a toast still on
 * screen under the same id, so `ToastLinkTest.php` can check the link never
 * outlives its message.
 *
 * Node strips type annotations on import; the resolve hook maps the `@/` alias
 * onto `resources/js/**.ts`.
 */
import { registerHooks } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [resourcesDir] = process.argv.slice(2);
const root = path.resolve(resourcesDir);

registerHooks({
    resolve: (specifier, context, nextResolve) => {
        if (specifier.startsWith('@/')) {
            return nextResolve(pathToFileURL(path.join(root, `${specifier.slice(2)}.ts`)).href, context);
        }

        return nextResolve(specifier, context);
    },
});

const { toastLinkOptions } = await import(pathToFileURL(path.join(root, 'lib/toastLink.ts')).href);

const visited: string[] = [];
const withLink = toastLinkOptions({ label: 'View post', url: '/posts?post=1' }, (url: string) => visited.push(url));
const withoutLink = toastLinkOptions(undefined, (url: string) => visited.push(url));

withLink.action.onClick();

const merged = { ...withLink, ...withoutLink };

console.log(
    JSON.stringify({
        label: withLink.action.label,
        visited,
        widened: withLink.classes !== undefined,
        keysWithoutLink: Object.keys(withoutLink),
        mergedKeepsLink: merged.action !== undefined || merged.classes !== undefined,
    }),
);
