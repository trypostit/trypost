import { computed, type Ref } from 'vue';

import type { SourceRect } from '@/lib/imageCrop';
import {
    type CropPresetValue,
    cropSelection,
    type MediaEdit,
    presetRatio,
    workingSize,
} from '@/lib/mediaEditor';

export type ImageSize = { width: number; height: number };

const MIN_SELECTION_RATIO = 0.1;

const EMPTY_RECT: SourceRect = { sx: 0, sy: 0, sw: 0, sh: 0 };

/**
 * Crop geometry of one image edit, used by the media editor stage and dialog:
 * the working frame (after quarter turns), the crop rectangle, the locked
 * ratio and the actions that recentre or reset the crop.
 */
export const useImageCrop = (
    edit: Readonly<Ref<MediaEdit | undefined>>,
    natural: Readonly<Ref<ImageSize | null>>,
) => {
    const size = computed<ImageSize>(() =>
        natural.value && edit.value
            ? workingSize(natural.value, edit.value)
            : { width: 0, height: 0 },
    );
    const crop = computed<SourceRect>(() =>
        natural.value && edit.value
            ? cropSelection(natural.value, edit.value)
            : EMPTY_RECT,
    );
    const ratio = computed<number | null>(() =>
        edit.value ? presetRatio(edit.value.preset, size.value) : null,
    );
    const minSize = computed(
        () =>
            Math.min(size.value.width, size.value.height) * MIN_SELECTION_RATIO,
    );
    const isCentered = computed(
        () =>
            Math.abs(crop.value.sx - (size.value.width - crop.value.sw) / 2) <
                0.5 &&
            Math.abs(crop.value.sy - (size.value.height - crop.value.sh) / 2) <
                0.5,
    );

    const centerSelection = (): void => {
        if (!edit.value) return;
        edit.value.selection = {
            ...crop.value,
            sx: (size.value.width - crop.value.sw) / 2,
            sy: (size.value.height - crop.value.sh) / 2,
        };
    };

    const resetGeometry = (preset: CropPresetValue = 'original'): void => {
        if (!edit.value) return;
        Object.assign(edit.value, {
            preset,
            selection: null,
            quarterTurns: 0,
            flipX: false,
            flipY: false,
            straighten: 0,
        });
    };

    return {
        size,
        crop,
        ratio,
        minSize,
        isCentered,
        centerSelection,
        resetGeometry,
    };
};
