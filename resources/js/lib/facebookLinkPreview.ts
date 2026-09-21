/**
 * Mirror of `App\Support\FacebookLinkPreview`. Facebook rejects many
 * facebook.com, fb.com and fb.me links, so the preview skips the same hosts
 * the publisher does.
 */
const FACEBOOK_HOSTS = ['facebook.com', 'fb.com', 'fb.me'] as const;

const trimTrailingPunctuation = (url: string): string => {
    const trimmed = url.replace(/[.,;:!?]$/, '');

    return trimmed.endsWith(')') && !trimmed.includes('(')
        ? trimmed.slice(0, -1)
        : trimmed;
};

const isFacebookOwnedUrl = (url: string): boolean => {
    if (!URL.canParse(url)) {
        return false;
    }

    const host = new URL(url).hostname.toLowerCase();

    return FACEBOOK_HOSTS.some(
        (domain) => host === domain || host.endsWith(`.${domain}`),
    );
};

export const facebookLinkPreviewUrl = (text: string): string | null =>
    [...text.matchAll(/https?:\/\/\S+/gu)]
        .map(([raw]) => trimTrailingPunctuation(raw))
        .find((url) => !isFacebookOwnedUrl(url)) ?? null;
