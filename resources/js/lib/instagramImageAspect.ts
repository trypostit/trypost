import { getMediaRulesForContentType } from '@/composables/useMediaRules';
import { isImage } from '@/lib/mediaType';
import { ContentType } from '@/types/content-type';
import type { MediaItem } from '@/types/media';

export interface InstagramImageAspectIssue {
    index: number;
    ratio: number;
    min: number;
    max: number;
}

export const getInstagramImageAspectIssues = (
    contentType: string,
    media: MediaItem[],
): InstagramImageAspectIssue[] => {
    if (contentType !== ContentType.InstagramFeed) {
        return [];
    }

    const { aspectRatioMin: min, aspectRatioMax: max } =
        getMediaRulesForContentType(contentType);

    if (min === undefined || max === undefined) {
        return [];
    }

    return media.flatMap((item, index) => {
        if (!isImage(item)) {
            return [];
        }

        const { width = 0, height = 0 } = item.meta ?? {};
        if (width <= 0 || height <= 0) {
            return [];
        }

        const ratio = width / height;

        return ratio < min - 0.0001 || ratio > max + 0.0001
            ? [{ index, ratio, min, max }]
            : [];
    });
};
