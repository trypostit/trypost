import { getMediaRulesForContentType } from '@/composables/useMediaRules';
import date from '@/date';
import { isDocument, isGif, isImage, isMov, isVideo } from '@/lib/mediaType';
import type { MediaItem } from '@/types/media';

export type { MediaItem } from '@/types/media';

// Media-type detection lives in '@/lib/mediaType' — these aliases keep the
// names the rest of the app already imports from here pointing at it.
export const isVideoMedia = isVideo;
export const isDocumentMedia = isDocument;
export const isImageMedia = isImage;

export interface MediaValidationWarning {
    key: string; // short key, e.g. 'gif_not_allowed'
    params: Record<string, string>;
}

export const formatBytes = (bytes: number, decimal = false): string => {
    const unit = decimal ? 1000 : 1024;
    if (bytes >= unit ** 3) return (bytes / unit ** 3).toFixed(1) + ' GB';
    if (bytes >= unit ** 2) return (bytes / unit ** 2).toFixed(1) + ' MB';
    if (bytes >= unit) return (bytes / unit).toFixed(1) + ' KB';
    return bytes + ' B';
};

/**
 * Whether a cap was declared in decimal megabytes (Bluesky's lexicon says
 * 2 000 000 / 300 000 000), so both the cap and the file size are shown in the
 * same unit system — "300 MB" rather than a puzzling "286.1 MB".
 */
export const usesDecimalUnits = (cap: number | undefined): boolean =>
    cap !== undefined && cap % 1_000_000 === 0 && cap % (1024 * 1024) !== 0;

const formatAspect = (ratio: number): string => ratio.toFixed(2);

/**
 * Return the first violation found for a given content_type + media list.
 * Returns null when everything is valid.
 * Checks are prioritized: presence → counts → format → per-item constraints.
 */
export const getMediaValidationWarning = (
    contentType: string,
    media: MediaItem[],
): MediaValidationWarning | null => {
    if (!contentType) return { key: 'no_variant', params: {} };

    const rules = getMediaRulesForContentType(contentType);
    const videos = media.filter(isVideo);
    const documents = media.filter(isDocument);
    const images = media.filter(isImage);
    const gifs = media.filter(isGif);
    const movs = media.filter(isMov);
    const total = media.length;

    if (rules.requiresMedia && total === 0) {
        return { key: 'requires_media', params: {} };
    }
    if (total > rules.maxFiles) {
        return {
            key: 'max_files_exceeded',
            params: { max: String(rules.maxFiles), current: String(total) },
        };
    }
    if (rules.minFiles && total < rules.minFiles) {
        return {
            key: 'min_files_required',
            params: { min: String(rules.minFiles), current: String(total) },
        };
    }
    if (!rules.acceptVideos && videos.length > 0) {
        return { key: 'no_video_allowed', params: {} };
    }
    if (!rules.acceptImages && images.length > 0) {
        return { key: 'no_image_allowed', params: {} };
    }
    if (!rules.acceptDocuments && documents.length > 0) {
        return { key: 'no_document_allowed', params: {} };
    }
    if (rules.forbidsMixedMedia && videos.length > 0 && images.length > 0) {
        return { key: 'no_mixed_media', params: {} };
    }
    if (rules.acceptDocuments && documents.length > 0 && total > 1) {
        return { key: 'document_not_alone', params: {} };
    }
    if (!rules.acceptsGif && gifs.length > 0) {
        return { key: 'gif_not_allowed', params: {} };
    }
    if (!rules.acceptsMov && movs.length > 0) {
        return { key: 'mov_not_allowed', params: {} };
    }

    for (const m of media) {
        const size = m.size ?? 0;
        const width = m.meta?.width ?? 0;
        const height = m.meta?.height ?? 0;
        const duration = m.meta?.duration ?? 0;

        if (isDocument(m)) {
            if (rules.maxDocumentBytes && size > rules.maxDocumentBytes) {
                return {
                    key: 'document_too_large',
                    params: {
                        max: formatBytes(
                            rules.maxDocumentBytes,
                            usesDecimalUnits(rules.maxDocumentBytes),
                        ),
                        current: formatBytes(
                            size,
                            usesDecimalUnits(rules.maxDocumentBytes),
                        ),
                    },
                };
            }
            continue;
        }

        if (isVideo(m)) {
            if (rules.maxVideoBytes && size > rules.maxVideoBytes) {
                return {
                    key: 'video_too_large',
                    params: {
                        max: formatBytes(
                            rules.maxVideoBytes,
                            usesDecimalUnits(rules.maxVideoBytes),
                        ),
                        current: formatBytes(
                            size,
                            usesDecimalUnits(rules.maxVideoBytes),
                        ),
                    },
                };
            }
            if (
                rules.maxVideoDurationSec &&
                duration > rules.maxVideoDurationSec
            ) {
                return {
                    key: 'video_too_long',
                    params: {
                        max: date.formatDurationWords(
                            rules.maxVideoDurationSec,
                        ),
                        current: date.formatDurationWords(duration),
                    },
                };
            }
        } else if (rules.maxImageBytes && size > rules.maxImageBytes) {
            return {
                key: 'image_too_large',
                params: {
                    max: formatBytes(
                        rules.maxImageBytes,
                        usesDecimalUnits(rules.maxImageBytes),
                    ),
                    current: formatBytes(
                        size,
                        usesDecimalUnits(rules.maxImageBytes),
                    ),
                },
            };
        }

        if (width > 0 && height > 0 && !(rules.autoFitsImage && isImage(m))) {
            const ratio = width / height;
            if (rules.aspectRatioMin && ratio < rules.aspectRatioMin) {
                return {
                    key: 'aspect_ratio_too_narrow',
                    params: {
                        current: formatAspect(ratio),
                        min: formatAspect(rules.aspectRatioMin),
                    },
                };
            }
            if (rules.aspectRatioMax && ratio > rules.aspectRatioMax) {
                return {
                    key: 'aspect_ratio_too_wide',
                    params: {
                        current: formatAspect(ratio),
                        max: formatAspect(rules.aspectRatioMax),
                    },
                };
            }
        }
    }

    return null;
};

/**
 * Returns a short reason key when a single media item doesn't fit a content
 * type's per-item rules (type, size, duration, aspect ratio). Set-level rules
 * (count, requires_media) are NOT checked here — use getMediaValidationWarning
 * for those.
 */
export const getMediaItemIssue = (
    item: MediaItem,
    contentType: string,
): string | null => {
    if (!contentType) return null;

    const rules = getMediaRulesForContentType(contentType);
    const itemIsVideo = isVideo(item);
    const itemIsDocument = isDocument(item);
    const itemIsGif = isGif(item);
    const itemIsMov = isMov(item);

    if (itemIsDocument) {
        if (!rules.acceptDocuments) return 'no_document_allowed';
        const docSize = item.size ?? 0;
        if (rules.maxDocumentBytes && docSize > rules.maxDocumentBytes)
            return 'document_too_large';
        return null;
    }

    if (itemIsVideo && !rules.acceptVideos) return 'no_video_allowed';
    if (!itemIsVideo && !rules.acceptImages) return 'no_image_allowed';
    if (itemIsGif && !rules.acceptsGif) return 'gif_not_allowed';
    if (itemIsMov && !rules.acceptsMov) return 'mov_not_allowed';

    const size = item.size ?? 0;
    if (itemIsVideo && rules.maxVideoBytes && size > rules.maxVideoBytes)
        return 'video_too_large';
    if (!itemIsVideo && rules.maxImageBytes && size > rules.maxImageBytes)
        return 'image_too_large';

    const duration = item.meta?.duration ?? 0;
    if (
        itemIsVideo &&
        rules.maxVideoDurationSec &&
        duration > rules.maxVideoDurationSec
    ) {
        return 'video_too_long';
    }

    const width = item.meta?.width ?? 0;
    const height = item.meta?.height ?? 0;
    if (width > 0 && height > 0 && !(rules.autoFitsImage && isImage(item))) {
        const ratio = width / height;
        if (rules.aspectRatioMin && ratio < rules.aspectRatioMin)
            return 'aspect_ratio_too_narrow';
        if (rules.aspectRatioMax && ratio > rules.aspectRatioMax)
            return 'aspect_ratio_too_wide';
    }

    return null;
};
