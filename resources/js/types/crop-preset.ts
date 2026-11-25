/** A crop the media editor offers (`App\Enums\Media\CropPreset`): free, the image's own ratio, or a fixed width:height ratio. */
export const CropPreset = {
    Freeform: 'freeform',
    Original: 'original',
    Portrait9x16: '9:16',
    Portrait2x3: '2:3',
    Portrait3x4: '3:4',
    Portrait4x5: '4:5',
    Square: '1:1',
    Landscape4x3: '4:3',
    Landscape191x100: '1.91:1',
    Landscape16x9: '16:9',
    Landscape2x1: '2:1',
} as const;

export type CropPresetValue = (typeof CropPreset)[keyof typeof CropPreset];

/** The presets without a fixed ratio, which lead the editor's list and never crop on their own. */
export const UNFIXED_CROP_PRESETS: ReadonlySet<CropPresetValue> = new Set([
    CropPreset.Freeform,
    CropPreset.Original,
]);
