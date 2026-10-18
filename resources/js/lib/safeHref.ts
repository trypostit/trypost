const SAFE_PROTOCOLS = ['http:', 'https:'];

/**
 * A URL a network sent, kept only when it is an absolute http(s) link, so a
 * `javascript:` or `data:` value can never end up in an `href`.
 */
export const safeHref = (url: string | null | undefined): string | undefined => {
    if (!url) {
        return undefined;
    }

    try {
        return SAFE_PROTOCOLS.includes(new URL(url).protocol) ? url : undefined;
    } catch {
        return undefined;
    }
};
