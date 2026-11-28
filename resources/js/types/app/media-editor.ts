import type { CropPresetValue } from '@/types/app/crop-preset';
import type { SourceRect } from '@/types/app/image-crop';
import type { CoverImage, CoverSource, MediaUserTag } from '@/types/app/media';

export const FILTER_PRESETS = ['original', 'vivid', 'mono', 'sepia'] as const;
export type FilterPreset = (typeof FILTER_PRESETS)[number];

export const ADJUSTMENTS = [
    'brightness',
    'contrast',
    'saturation',
    'warmth',
] as const;
export type Adjustment = (typeof ADJUSTMENTS)[number];

/** A crop button of the media editor: its preset, its width:height ratio and the shape of its icon. */
export type CropPresetOption = {
    value: CropPresetValue;
    ratio: number | null;
    shape: 'freeform' | 'portrait' | 'square' | 'landscape';
};

export type MediaEdit = {
    preset: CropPresetValue;
    selection: SourceRect | null;
    quarterTurns: number;
    flipX: boolean;
    flipY: boolean;
    straighten: number;
    filter: FilterPreset;
    /** How strongly the filter applies, 0 to 100. */
    filterIntensity: number;
    adjustments: Record<Adjustment, number>;
    altText: string;
    userTags: MediaUserTag[];
    coverOffsetMs: number | null;
    coverImage: CoverImage | null;
    coverSource: CoverSource;
};

export type EditorTab = 'edit' | 'thumbnail' | 'alt' | 'tags';
