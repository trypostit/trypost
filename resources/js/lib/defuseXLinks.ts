import { Platform } from '@/types/platform';

/**
 * Mirror of the `Platform::X` branch of `App\Services\Social\ContentSanitizer`.
 * The editor needs it to count characters and render the preview against the text
 * that will actually be posted, and it cannot ask the server on every keystroke.
 *
 * Keep it in step with the PHP: a scheme (with optional userinfo) or `www.` proves
 * a token is a URL on its own, while a bare host only counts when its last label is
 * a delegated TLD — the single thing telling `acme.com` apart from `Node.js`.
 *
 * The character before a candidate is consumed and put back rather than checked with
 * a lookbehind, which Safari only understands from 16.4 on.
 */
const LINK_PATTERN =
    /(^|[^\p{L}\p{N}\p{M}_@/.])((?:https?:\/\/(?:[^\s/@]+@)?)?(?:www\.)?)((?:[\p{L}\p{N}](?:[\p{L}\p{N}\p{M}-]*[\p{L}\p{N}\p{M}])?\.)+([\p{L}\p{N}\p{M}-]{2,63}))(?![\p{L}\p{N}\p{M}-])((?:\/\S*)?)/giu;

/**
 * The delegated TLDs are handed in rather than declared here: `App\Support\LinkTlds`
 * is the only place the list lives, and the editor receives it as a page prop.
 */
export const defuseXLinks = (content: string, tlds: ReadonlySet<string>): string =>
    content.replace(
        LINK_PATTERN,
        (whole: string, boundary: string, prefix: string, host: string, tld: string, path: string): string =>
            prefix === '' && !tlds.has(tld.toLowerCase())
                ? whole
                : boundary + host.replaceAll('.', '(.)') + path,
    );

/**
 * The content as the given platform will show it. Only X rewrites anything today,
 * so every other network gets its text back untouched.
 */
export const contentForPlatform = (
    content: string,
    platform: string,
    tlds: ReadonlySet<string>,
): string => (platform === Platform.X && tlds.size > 0 ? defuseXLinks(content, tlds) : content);
