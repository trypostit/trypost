/**
 * Mirrors `App\Support\FacebookLinkPreview`. The composer has to pick the same
 * URL the publisher will send as `link`, or the preview card is a different
 * page than the one Facebook receives. One trailing punctuation mark and an
 * unmatched closing parenthesis are trimmed, matching
 * `UrlDetector::trimTrailingPunctuation`.
 */
const FACEBOOK_HOSTS = ['facebook.com', 'fb.com', 'fb.me'];

const trimTrailingPunctuation = (url: string): string => {
    if (/[.,;:!?]$/.test(url)) {
        url = url.slice(0, -1);
    }

    if (url.endsWith(')') && !url.includes('(')) {
        url = url.slice(0, -1);
    }

    return url;
};

const isFacebookOwnedUrl = (url: string): boolean => {
    let host: string;

    try {
        host = new URL(url).hostname.toLowerCase();
    } catch {
        return false;
    }

    return FACEBOOK_HOSTS.some((domain) => host === domain || host.endsWith(`.${domain}`));
};

export const facebookLinkPreviewUrl = (text: string): string | null => {
    for (const match of text.matchAll(/https?:\/\/\S+/gu)) {
        const url = trimTrailingPunctuation(match[0]);

        if (!isFacebookOwnedUrl(url)) {
            return url;
        }
    }

    return null;
};
