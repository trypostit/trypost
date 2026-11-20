import { isVideo } from '@/lib/mediaType';
import { ContentType, type ContentTypeValue } from '@/types/content-type';
import type { MediaItem } from '@/types/media';
import { Platform, type PlatformValue } from '@/types/platform';

/**
 * Networks that offer no post-type choice: the attached media decides it, so the
 * composer shows no type radios for them.
 */
const DERIVED_CONTENT_TYPES: Partial<Record<PlatformValue, (media: MediaItem[]) => ContentTypeValue>> = {
    [Platform.Pinterest]: (media) => {
        if (media.some((item) => isVideo(item))) {
            return ContentType.PinterestVideoPin;
        }

        return media.length > 1 ? ContentType.PinterestCarousel : ContentType.PinterestPin;
    },
    [Platform.TikTok]: (media) =>
        media.length > 0 && !media.some((item) => isVideo(item))
            ? ContentType.TikTokPhoto
            : ContentType.TikTokVideo,
};

export const derivesContentType = (platform: string): boolean =>
    platform in DERIVED_CONTENT_TYPES;

/** The content type the media decides on this network, or null when the user picks it. */
export const derivedContentTypeFor = (platform: PlatformValue, media: MediaItem[]): ContentTypeValue | null =>
    DERIVED_CONTENT_TYPES[platform]?.(media) ?? null;
