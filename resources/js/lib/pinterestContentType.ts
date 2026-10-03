import { isVideo } from '@/lib/mediaType';
import { ContentType } from '@/types/content-type';
import type { MediaItem } from '@/types/media';

/** Pinterest offers no post-type choice: the media decides it. */
export const pinterestContentTypeFor = (media: MediaItem[]): string => {
    if (media.some((item) => isVideo(item))) {
        return ContentType.PinterestVideoPin;
    }

    return media.length > 1
        ? ContentType.PinterestCarousel
        : ContentType.PinterestPin;
};
