import { getMediaRulesForContentType } from '@/composables/useMediaRules';
import date from '@/date';
import type { MediaRules } from '@/lib/contentTypeMediaRules';
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

/** Same units and precision as `formatBytes` in ContentTypeCompatibleWithMedia.php, so the editor and the server's 422 agree on the numbers. */
const formatBytes = (bytes: number, decimal = false, precision = 1): string => {
    const unit = decimal ? 1000 : 1024;
    if (bytes >= unit ** 3) return `${(bytes / unit ** 3).toFixed(precision)} GB`;
    if (bytes >= unit ** 2) return `${(bytes / unit ** 2).toFixed(precision)} MB`;
    if (bytes >= unit) return `${(bytes / unit).toFixed(precision)} KB`;
    return `${bytes} B`;
};

/** Caps declared in decimal megabytes (Bluesky) render as "300 MB", not "286 MB"; the cap is whole, the size keeps a decimal. */
const sizeParams = (cap: number, size: number): Record<string, string> => {
    const decimal = cap % 1_000_000 === 0 && cap % (1024 * 1024) !== 0;

    return { max: formatBytes(cap, decimal, 0), current: formatBytes(size, decimal, 1) };
};

const formatAspect = (ratio: number): string => ratio.toFixed(2);

const warning = (key: string, params: Record<string, string> = {}): MediaValidationWarning => ({ key, params });

const firstWarning = (...candidates: Array<MediaValidationWarning | false | null | undefined>): MediaValidationWarning | null =>
    candidates.find((candidate): candidate is MediaValidationWarning => Boolean(candidate)) ?? null;

const itemConstraintWarning = (item: MediaItem, rules: MediaRules): MediaValidationWarning | null => {
    const size = item.size ?? 0;
    const duration = item.meta?.duration ?? 0;
    const width = item.meta?.width ?? 0;
    const height = item.meta?.height ?? 0;

    if (isDocument(item)) {
        return rules.maxDocumentBytes && size > rules.maxDocumentBytes
            ? warning('document_too_large', sizeParams(rules.maxDocumentBytes, size))
            : null;
    }

    if (isVideo(item)) {
        if (rules.maxVideoBytes && size > rules.maxVideoBytes) {
            return warning('video_too_large', sizeParams(rules.maxVideoBytes, size));
        }

        if (rules.maxVideoDurationSec && duration > rules.maxVideoDurationSec) {
            // Ceil, like the server: a 60.2s clip is over a 60s cap and must not read as "60s".
            return warning('video_too_long', {
                max: date.formatDurationWords(rules.maxVideoDurationSec),
                current: date.formatDurationWords(Math.ceil(duration)),
            });
        }
    } else if (isImage(item) && rules.maxImageBytes && size > rules.maxImageBytes) {
        return warning('image_too_large', sizeParams(rules.maxImageBytes, size));
    }

    if (width > 0 && height > 0 && ! (rules.autoFitsImage && isImage(item))) {
        const ratio = width / height;

        if (rules.aspectRatioMin && ratio < rules.aspectRatioMin) {
            return warning('aspect_ratio_too_narrow', { current: formatAspect(ratio), min: formatAspect(rules.aspectRatioMin) });
        }

        if (rules.aspectRatioMax && ratio > rules.aspectRatioMax) {
            return warning('aspect_ratio_too_wide', { current: formatAspect(ratio), max: formatAspect(rules.aspectRatioMax) });
        }
    }

    return null;
};

/**
 * Return the first violation found for a given content_type + media list.
 * Returns null when everything is valid.
 * Checks are prioritized: presence → counts → format → per-item constraints.
 */
export const getMediaValidationWarning = (
    contentType: string,
    media: MediaItem[],
): MediaValidationWarning | null => {
    if (! contentType) return warning('no_variant');

    const rules = getMediaRulesForContentType(contentType);
    const videos = media.filter(isVideo);
    const documents = media.filter(isDocument);
    const images = media.filter(isImage);
    const total = media.length;

    return firstWarning(
        rules.requiresMedia && total === 0 && warning('requires_media'),
        total > rules.maxFiles && warning('max_files_exceeded', { max: String(rules.maxFiles), current: String(total) }),
        total < (rules.minFiles ?? 0) && warning('min_files_required', { min: String(rules.minFiles), current: String(total) }),
        ! rules.acceptVideos && videos.length > 0 && warning('no_video_allowed'),
        ! rules.acceptImages && images.length > 0 && warning('no_image_allowed'),
        ! rules.acceptDocuments && documents.length > 0 && warning('no_document_allowed'),
        rules.forbidsMixedMedia && videos.length > 0 && images.length > 0 && warning('no_mixed_media'),
        rules.acceptDocuments && documents.length > 0 && total > 1 && warning('document_not_alone'),
        ! rules.acceptsGif && media.some(isGif) && warning('gif_not_allowed'),
        ! rules.acceptsMov && media.some(isMov) && warning('mov_not_allowed'),
        ...media.map((item) => itemConstraintWarning(item, rules)),
    );
};

/**
 * Returns a short reason key when a single media item doesn't fit a content
 * type's per-item rules (type, size, duration, aspect ratio). Set-level rules
 * (count, requires_media) are NOT checked here — use getMediaValidationWarning
 * for those.
 */
export const getMediaItemIssue = (item: MediaItem, contentType: string): string | null => {
    if (! contentType) return null;

    const rules = getMediaRulesForContentType(contentType);

    if (isDocument(item)) {
        return firstWarning(
            ! rules.acceptDocuments && warning('no_document_allowed'),
            itemConstraintWarning(item, rules),
        )?.key ?? null;
    }

    return firstWarning(
        isVideo(item) && ! rules.acceptVideos && warning('no_video_allowed'),
        ! isVideo(item) && ! rules.acceptImages && warning('no_image_allowed'),
        isGif(item) && ! rules.acceptsGif && warning('gif_not_allowed'),
        isMov(item) && ! rules.acceptsMov && warning('mov_not_allowed'),
        itemConstraintWarning(item, rules),
    )?.key ?? null;
};
