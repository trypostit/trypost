import type { Page } from '@inertiajs/core';

import type { CropPresetValue } from '@/lib/mediaEditor';

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
    aspectRatioImagesOnly?: boolean;
    cropPresets: CropPresetValue[];
    supportsAltText: boolean;
    supportsUserTags: boolean;
    supportsVideoCover: boolean;
    platformLabel?: string;
    imageMinWidth?: number;
    imageMinHeight?: number;
    imageMaxWidth?: number;
    imageMaxHeight?: number;
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
    aspect_ratio_images_only: boolean;
    crop_presets: CropPresetValue[];
    supports_alt_text: boolean;
    supports_user_tags: boolean;
    supports_video_cover: boolean;
    platform_label: string;
    image_min_width: number | null;
    image_min_height: number | null;
    image_max_width: number | null;
    image_max_height: number | null;
};

type ContentTypeMediaRulesMap = Record<string, ContentTypeMediaRule>;

let cachedRules: ContentTypeMediaRulesMap | null = null;
let cachedDefaultCropPresets: CropPresetValue[] = [];

export const syncContentTypeMediaRules = (page: Page): void => {
    const rules = page.props.contentTypeMediaRules as ContentTypeMediaRulesMap | undefined;
    const defaultCropPresets = page.props.defaultCropPresets as CropPresetValue[] | undefined;

    if (rules) {
        cachedRules = rules;
    }

    if (defaultCropPresets) {
        cachedDefaultCropPresets = defaultCropPresets;
    }
};

/** Crop presets when no channel applies: ContentType::defaultCropPresets(). */
export const defaultCropPresets = (): CropPresetValue[] => cachedDefaultCropPresets;

export const mediaRuleFor = (contentType: string): ContentTypeMediaRule | undefined => {
    return cachedRules?.[contentType];
};

/** A null cap from the server is simply no cap: every consumer reads the optional fields with `??` / `&&`. */
export const toMediaRules = (rule: ContentTypeMediaRule): MediaRules => ({
    maxFiles: rule.max_files,
    minFiles: rule.min_files ?? undefined,
    acceptImages: rule.accept_images,
    acceptVideos: rule.accept_videos,
    acceptDocuments: rule.accept_documents,
    requiresMedia: rule.requires_media,
    acceptsGif: rule.accepts_gif,
    acceptsMov: rule.accepts_mov,
    forbidsMixedMedia: rule.forbids_mixed_media,
    maxImageBytes: rule.max_image_bytes ?? undefined,
    maxVideoBytes: rule.max_video_bytes ?? undefined,
    maxDocumentBytes: rule.max_document_bytes ?? undefined,
    maxVideoDurationSec: rule.max_video_duration_sec ?? undefined,
    aspectRatioMin: rule.aspect_ratio_min ?? undefined,
    aspectRatioMax: rule.aspect_ratio_max ?? undefined,
    autoFitsImage: rule.auto_fits_image,
    aspectRatioImagesOnly: rule.aspect_ratio_images_only,
    cropPresets: rule.crop_presets,
    supportsAltText: rule.supports_alt_text,
    supportsUserTags: rule.supports_user_tags,
    supportsVideoCover: rule.supports_video_cover,
    platformLabel: rule.platform_label,
    imageMinWidth: rule.image_min_width ?? undefined,
    imageMinHeight: rule.image_min_height ?? undefined,
    imageMaxWidth: rule.image_max_width ?? undefined,
    imageMaxHeight: rule.image_max_height ?? undefined,
});
