import { computed, type Ref, type ComputedRef } from 'vue';

import { fromMimeType, MediaType } from '@/lib/mediaType';

export interface MediaRules {
    maxFiles: number;
    minFiles?: number;
    acceptImages: boolean;
    acceptVideos: boolean;
    acceptDocuments?: boolean;
    requiresMedia: boolean;
    acceptsGif: boolean;
    forbidsMixedMedia?: boolean;
    maxImageBytes?: number;
    maxVideoBytes?: number;
    maxDocumentBytes?: number;
    maxVideoDurationSec?: number;
    aspectRatioMin?: number;
    aspectRatioMax?: number;
    autoFitsImage?: boolean;
}

const MB = 1024 * 1024;
const GB = 1024 * MB;

const CONTENT_TYPE_RULES: Record<string, MediaRules> = {
    // Instagram
    instagram_feed: {
        maxFiles: 10, acceptImages: true, acceptVideos: true, requiresMedia: true,
        acceptsGif: false,
        maxImageBytes: 8 * MB, maxVideoBytes: 100 * MB, maxVideoDurationSec: 60,
        aspectRatioMin: 0.8, aspectRatioMax: 1.91,
    },
    instagram_reel: {
        maxFiles: 1, acceptImages: false, acceptVideos: true, requiresMedia: true,
        acceptsGif: false,
        maxVideoBytes: 1 * GB, maxVideoDurationSec: 15 * 60,
        aspectRatioMin: 0.5, aspectRatioMax: 0.6,
    },
    instagram_story: {
        maxFiles: 1, acceptImages: true, acceptVideos: true, requiresMedia: true,
        acceptsGif: false,
        maxImageBytes: 8 * MB, maxVideoBytes: 100 * MB, maxVideoDurationSec: 60,
        aspectRatioMin: 0.5, aspectRatioMax: 0.6, autoFitsImage: true,
    },

    // Facebook
    facebook_post: {
        maxFiles: 10, acceptImages: true, acceptVideos: true, requiresMedia: false,
        acceptsGif: false,
        maxImageBytes: 4 * MB, maxVideoBytes: 10 * GB, maxVideoDurationSec: 240 * 60,
    },
    facebook_reel: {
        maxFiles: 1, acceptImages: false, acceptVideos: true, requiresMedia: true,
        acceptsGif: false,
        maxVideoBytes: 1 * GB, maxVideoDurationSec: 90,
        aspectRatioMin: 0.5, aspectRatioMax: 0.6,
    },
    facebook_story: {
        maxFiles: 1, acceptImages: false, acceptVideos: true, requiresMedia: true,
        acceptsGif: false,
        maxVideoBytes: 1 * GB, maxVideoDurationSec: 60,
        aspectRatioMin: 0.5, aspectRatioMax: 0.6,
    },

    // LinkedIn — one type per account kind; the publish format (single image,
    // multi-image, video, or PDF document) is inferred from the attached media.
    // Images (up to 10) XOR one video XOR one PDF, never mixed.
    linkedin_post: {
        maxFiles: 10, acceptImages: true, acceptVideos: true, acceptDocuments: true,
        requiresMedia: false, acceptsGif: false, forbidsMixedMedia: true,
        maxImageBytes: 5 * MB, maxVideoBytes: 5 * GB, maxVideoDurationSec: 10 * 60, maxDocumentBytes: 100 * MB,
    },
    linkedin_page_post: {
        maxFiles: 10, acceptImages: true, acceptVideos: true, acceptDocuments: true,
        requiresMedia: false, acceptsGif: false, forbidsMixedMedia: true,
        maxImageBytes: 5 * MB, maxVideoBytes: 5 * GB, maxVideoDurationSec: 10 * 60, maxDocumentBytes: 100 * MB,
    },

    // TikTok
    tiktok_video: {
        maxFiles: 1, acceptImages: false, acceptVideos: true, requiresMedia: true,
        acceptsGif: false,
        // maxVideoDurationSec is enforced dynamically via creator_info
    },
    tiktok_photo: {
        maxFiles: 35, minFiles: 1, acceptImages: true, acceptVideos: false, requiresMedia: true,
        acceptsGif: false,
    },

    // YouTube
    youtube_short: {
        maxFiles: 1, acceptImages: false, acceptVideos: true, requiresMedia: true,
        acceptsGif: false,
        maxVideoBytes: 256 * GB, maxVideoDurationSec: 3 * 60,
        aspectRatioMin: 0.5, aspectRatioMax: 0.6,
    },

    // Pinterest
    pinterest_pin: {
        maxFiles: 1, acceptImages: true, acceptVideos: false, requiresMedia: true,
        acceptsGif: false,
        maxImageBytes: 20 * MB,
    },
    pinterest_video_pin: {
        maxFiles: 1, acceptImages: false, acceptVideos: true, requiresMedia: true,
        acceptsGif: false,
        maxVideoBytes: 2 * GB, maxVideoDurationSec: 15 * 60,
    },
    pinterest_carousel: {
        maxFiles: 5, minFiles: 2, acceptImages: true, acceptVideos: false, requiresMedia: true,
        acceptsGif: false,
        maxImageBytes: 20 * MB,
    },

    // X (Twitter) — accepts GIF with animation
    x_post: {
        maxFiles: 4, acceptImages: true, acceptVideos: true, requiresMedia: false,
        acceptsGif: true,
        maxImageBytes: 5 * MB, maxVideoBytes: 512 * MB, maxVideoDurationSec: 140,
    },

    // Threads
    threads_post: {
        maxFiles: 10, acceptImages: true, acceptVideos: true, requiresMedia: false,
        acceptsGif: false,
        maxImageBytes: 8 * MB, maxVideoBytes: 1 * GB, maxVideoDurationSec: 5 * 60,
    },

    // Bluesky — accepts GIF; tight image size (auto-resized by backend).
    // The embed is images XOR video, so the two can't be combined in one post.
    bluesky_post: {
        maxFiles: 4, acceptImages: true, acceptVideos: true, requiresMedia: false,
        acceptsGif: true, forbidsMixedMedia: true,
        maxVideoBytes: 100 * MB, maxVideoDurationSec: 60,
    },

    // Mastodon — accepts GIF
    mastodon_post: {
        maxFiles: 4, acceptImages: true, acceptVideos: true, requiresMedia: false,
        acceptsGif: true,
        maxImageBytes: 10 * MB, maxVideoBytes: 40 * MB,
    },
};

const DEFAULT_RULES: MediaRules = {
    maxFiles: 10,
    acceptImages: true,
    acceptVideos: true,
    requiresMedia: false,
    acceptsGif: true,
};

export function useMediaRules(contentType: Ref<string> | ComputedRef<string>) {
    const rules = computed<MediaRules>(() => {
        return CONTENT_TYPE_RULES[contentType.value] || DEFAULT_RULES;
    });

    const acceptMimeTypes = computed<string>(() => {
        const types: string[] = [];
        if (rules.value.acceptImages) {
            types.push('image/*');
        }
        if (rules.value.acceptVideos) {
            types.push('video/*');
        }
        if (rules.value.acceptDocuments) {
            types.push('application/pdf');
        }
        return types.join(',');
    });

    const canAddMore = computed(() => {
        return (currentCount: number) => currentCount < rules.value.maxFiles;
    });

    const isValidFileType = computed(() => {
        return (file: File): boolean => {
            const type = fromMimeType(file.type);

            if (type === MediaType.Image && !rules.value.acceptImages) {
                return false;
            }
            if (type === MediaType.Video && !rules.value.acceptVideos) {
                return false;
            }
            if (type === MediaType.Document) {
                return Boolean(rules.value.acceptDocuments);
            }
            return type === MediaType.Image || type === MediaType.Video;
        };
    });

    const getAcceptDescription = computed<string>(() => {
        if (rules.value.acceptDocuments && !rules.value.acceptImages && !rules.value.acceptVideos) {
            return 'PDF document';
        }
        if (rules.value.acceptImages && rules.value.acceptVideos) {
            return 'Images or videos';
        }
        if (rules.value.acceptImages) {
            return 'Images only';
        }
        if (rules.value.acceptVideos) {
            return 'Videos only';
        }
        return 'No media';
    });

    return {
        rules,
        acceptMimeTypes,
        canAddMore,
        isValidFileType,
        getAcceptDescription,
    };
}

export function getMediaRulesForContentType(contentType: string): MediaRules {
    return CONTENT_TYPE_RULES[contentType] || DEFAULT_RULES;
}
