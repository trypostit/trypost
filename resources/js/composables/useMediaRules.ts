import { computed, type Ref, type ComputedRef } from 'vue';

import {
    mediaRuleFor,
    toMediaRules,
    type MediaRules,
} from '@/lib/contentTypeMediaRules';
import { fromMimeType, MediaType } from '@/lib/mediaType';

export type { MediaRules };

/**
 * Fallback only when Inertia once-props have not synced yet (or unknown type).
 * Real limits live in App\Enums\PostPlatform\ContentType::mediaRules().
 * Keep this fail-closed (no GIF) — most content types reject animated GIFs.
 */
const DEFAULT_RULES: MediaRules = {
    maxFiles: 10,
    acceptImages: true,
    acceptVideos: true,
    requiresMedia: false,
    acceptsGif: false,
    acceptsMov: true,
};

export const getMediaRulesForContentType = (contentType: string): MediaRules => {
    const shared = mediaRuleFor(contentType);

    if (!shared) {
        return DEFAULT_RULES;
    }

    return toMediaRules(shared);
};

export const useMediaRules = (contentType: Ref<string> | ComputedRef<string>) => {
    const rules = computed<MediaRules>(() => getMediaRulesForContentType(contentType.value));

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
};
