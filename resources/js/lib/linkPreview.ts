import { firstHttpUrl } from '@/composables/useLinkCard';
import { facebookLinkPreviewUrl } from '@/lib/facebookLinkPreview';
import { ContentType } from '@/types/content-type';
import { Platform } from '@/types/platform';

/** Networks whose editor shows the link preview card under the text. */
export const LINK_PREVIEW_PLATFORMS: ReadonlySet<string> = new Set([
    Platform.Facebook,
    Platform.Threads,
    Platform.Bluesky,
    Platform.LinkedIn,
    Platform.LinkedInPage,
]);

/** Threads turns the first link into a card on its own, so its card cannot be dropped. */
export const linkPreviewDroppable = (platform: string): boolean =>
    LINK_PREVIEW_PLATFORMS.has(platform) && platform !== Platform.Threads;

/** URL whose card the editor shows, or null when the network has no card or the user dropped it. */
export const linkPreviewUrl = (
    platform: string,
    contentType: string,
    meta: Record<string, any> | undefined,
    text: string,
): string | null => {
    if (
        !LINK_PREVIEW_PLATFORMS.has(platform) ||
        contentType === ContentType.ThreadsGhostPost ||
        meta?.link_preview === false
    ) {
        return null;
    }

    return platform === Platform.Facebook
        ? facebookLinkPreviewUrl(text)
        : firstHttpUrl(text);
};
