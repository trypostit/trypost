<?php

declare(strict_types=1);

namespace App\Enums\Media;

/**
 * A crop the media editor offers: free, the image's own ratio, or a fixed
 * width:height ratio. Mirrored by `CropPresetValue` in `lib/mediaEditor.ts`.
 */
enum CropPreset: string
{
    case Freeform = 'freeform';
    case Original = 'original';
    case Portrait9x16 = '9:16';
    case Portrait2x3 = '2:3';
    case Portrait3x4 = '3:4';
    case Portrait4x5 = '4:5';
    case Square = '1:1';
    case Landscape4x3 = '4:3';
    case Landscape191x100 = '1.91:1';
    case Landscape16x9 = '16:9';
    case Landscape2x1 = '2:1';

    /**
     * Whether the preset is a fixed width:height ratio, which Freeform and
     * Original are not (`UNFIXED_CROP_PRESETS` in `types/crop-preset.ts`).
     */
    public function hasFixedRatio(): bool
    {
        return match ($this) {
            self::Freeform, self::Original => false,
            self::Portrait9x16, self::Portrait2x3, self::Portrait3x4, self::Portrait4x5, self::Square,
            self::Landscape4x3, self::Landscape191x100, self::Landscape16x9, self::Landscape2x1 => true,
        };
    }

    /**
     * The presets as the editor reads them.
     *
     * @param  list<self>  $presets
     * @return list<string>
     */
    public static function valuesOf(array $presets): array
    {
        return collect($presets)->map(fn (self $preset): string => $preset->value)->all();
    }
}
