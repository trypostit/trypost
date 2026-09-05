import { getMediaRulesForContentType } from '@/composables/useMediaRules';

/**
 * The AI wizard only produces images. When `imageOnly` is true
 * (previewOnly / generate context), drop video-only content types so they
 * can't be selected.
 */
export const filterImageCapableVariants = <T extends { value: string }>(
    variants: readonly T[],
    imageOnly: boolean,
): T[] => {
    if (! imageOnly) {
        return [...variants];
    }

    return variants.filter((variant) => getMediaRulesForContentType(variant.value).acceptImages);
};

/**
 * When the current content type was filtered out (e.g. Video Pin in Generate),
 * return the first remaining variant to switch to — or null when already valid.
 */
export const fallbackImageCapableVariant = (
    contentType: string,
    available: readonly { value: string }[],
): string | null => {
    if (available.some((variant) => variant.value === contentType)) {
        return null;
    }

    return available[0]?.value ?? null;
};
