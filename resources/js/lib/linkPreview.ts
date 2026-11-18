import { firstHttpUrl } from '@/composables/useLinkCard';
import { facebookLinkPreviewUrl } from '@/lib/facebookLinkPreview';
import {
    ContentType,
    type ContentTypeValue,
    POLL_CONTENT_TYPES,
} from '@/types/content-type';
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

/** Post types that draw no link card: a Threads ghost post and every poll. */
const NO_LINK_CARD_CONTENT_TYPES: ReadonlySet<ContentTypeValue> = new Set([
    ContentType.ThreadsGhostPost,
    ...POLL_CONTENT_TYPES,
]);

/** URL whose card the editor shows, or null when the network has no card or the user dropped it. */
export const linkPreviewUrl = (
    platform: string,
    contentType: ContentTypeValue,
    meta: Record<string, any> | undefined,
    text: string,
): string | null => {
    if (!LINK_PREVIEW_PLATFORMS.has(platform)) {
        return null;
    }

    if (NO_LINK_CARD_CONTENT_TYPES.has(contentType)) {
        return null;
    }

    if (meta?.link_preview === false) {
        return null;
    }

    if (platform === Platform.Facebook) {
        return facebookLinkPreviewUrl(text);
    }

    return firstHttpUrl(text);
};
