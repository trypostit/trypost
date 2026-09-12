import type { Page } from '@inertiajs/core';

/** CamelCase shape consumed by the Vue media picker / compliance checks. */
export type MediaRules = {
    maxFiles: number;
    minFiles?: number;
    acceptImages: boolean;
    acceptVideos: boolean;
    acceptDocuments?: boolean;
    requiresMedia: boolean;
    acceptsGif: boolean;
    acceptsMov: boolean;
    forbidsMixedMedia?: boolean;
    maxImageBytes?: number;
    maxVideoBytes?: number;
    maxDocumentBytes?: number;
    maxVideoDurationSec?: number;
    aspectRatioMin?: number;
    aspectRatioMax?: number;
    autoFitsImage?: boolean;
};

/**
 * Snake_case payload from ContentType::mediaRules() / mediaRulesForFrontend().
 */
export type ContentTypeMediaRule = {
    max_files: number;
    min_files: number | null;
    accept_images: boolean;
    accept_videos: boolean;
    accept_documents: boolean;
    requires_media: boolean;
    accepts_gif: boolean;
    accepts_mov: boolean;
    forbids_mixed_media: boolean;
    max_image_bytes: number | null;
    max_video_bytes: number | null;
    max_document_bytes: number | null;
    max_video_duration_sec: number | null;
    aspect_ratio_min: number | null;
    aspect_ratio_max: number | null;
    auto_fits_image: boolean;
};

type ContentTypeMediaRulesMap = Record<string, ContentTypeMediaRule>;

let cachedRules: ContentTypeMediaRulesMap | null = null;

export const syncContentTypeMediaRules = (page: Page): void => {
    const rules = page.props.contentTypeMediaRules as ContentTypeMediaRulesMap | undefined;

    if (rules) {
        cachedRules = rules;
    }
};

export const mediaRuleFor = (contentType: string): ContentTypeMediaRule | undefined => {
    return cachedRules?.[contentType];
};

export const toMediaRules = (rule: ContentTypeMediaRule): MediaRules => {
    const mapped: MediaRules = {
        maxFiles: rule.max_files,
        acceptImages: rule.accept_images,
        acceptVideos: rule.accept_videos,
        requiresMedia: rule.requires_media,
        acceptsGif: rule.accepts_gif,
        acceptsMov: rule.accepts_mov,
    };

    if (rule.min_files !== null) {
        mapped.minFiles = rule.min_files;
    }

    if (rule.accept_documents) {
        mapped.acceptDocuments = true;
    }

    if (rule.forbids_mixed_media) {
        mapped.forbidsMixedMedia = true;
    }

    if (rule.max_image_bytes !== null) {
        mapped.maxImageBytes = rule.max_image_bytes;
    }

    if (rule.max_video_bytes !== null) {
        mapped.maxVideoBytes = rule.max_video_bytes;
    }

    if (rule.max_document_bytes !== null) {
        mapped.maxDocumentBytes = rule.max_document_bytes;
    }

    if (rule.max_video_duration_sec !== null) {
        mapped.maxVideoDurationSec = rule.max_video_duration_sec;
    }

    if (rule.aspect_ratio_min !== null) {
        mapped.aspectRatioMin = rule.aspect_ratio_min;
    }

    if (rule.aspect_ratio_max !== null) {
        mapped.aspectRatioMax = rule.aspect_ratio_max;
    }

    if (rule.auto_fits_image) {
        mapped.autoFitsImage = true;
    }

    return mapped;
};
