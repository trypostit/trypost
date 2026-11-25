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
