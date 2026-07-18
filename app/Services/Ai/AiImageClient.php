<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\Workspace\ContentLanguage;
use App\Enums\Workspace\ImageStyle;
use App\Support\HexColorName;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Image;
use Throwable;

class AiImageClient
{
    public const MODEL = 'gpt-image-2';

    private const BRAND_DESCRIPTION_MAX = 200;

    /**
     * Generate raw image bytes via OpenAI gpt-image-2. Returns null on any
     * failure so the caller can fall back to a stock photo without throwing.
     *
     * @param  array<int, string>  $keywords
     */
    public function generate(
        array $keywords,
        ImageStyle $style,
        string $orientation = 'portrait',
        string $language = 'en',
        ?string $brandColor = null,
        ?string $backgroundColor = null,
        ?string $textColor = null,
        ?string $brandDescription = null,
        string $quality = 'low',
        int $timeout = 180,
    ): ?string {
        $clean = array_values(array_filter(array_map('trim', $keywords)));
        if ($clean === []) {
            return null;
        }

        $palette = $this->buildPaletteContext($brandColor, $backgroundColor, $textColor);

        $brandContext = null;
        if ($brandDescription !== null) {
            $trimmed = trim($brandDescription);
            if ($trimmed !== '') {
                $brandContext = mb_strlen($trimmed) > self::BRAND_DESCRIPTION_MAX
                    ? mb_substr($trimmed, 0, self::BRAND_DESCRIPTION_MAX).'…'
                    : $trimmed;
            }
        }

        $prompt = view('prompts.post_image.generator', [
            'style' => $style->value,
            'scene' => implode(', ', $clean),
            'language_name' => $this->languageName($language),
            'has_brand_palette' => data_get($palette, 'is_defined', false),
            'brand_color_name' => data_get($palette, 'brand_color_name'),
            'background_color_name' => data_get($palette, 'background_color_name'),
            'text_color_name' => data_get($palette, 'text_color_name'),
            'brand_context' => $brandContext,
        ])->render();

        try {
            $builder = Image::of($prompt)->quality($quality)->timeout($timeout);

            $builder = match ($orientation) {
                'portrait' => $builder->portrait(),
                'landscape' => $builder->landscape(),
                default => $builder->square(),
            };

            $image = $builder->generate(model: self::MODEL);
        } catch (Throwable $e) {
            Log::warning('AiImageClient: generation failed', [
                'style' => $style->value,
                'orientation' => $orientation,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $bytes = (string) $image;

        return $bytes !== '' ? $bytes : null;
    }

    private function languageName(string $code): string
    {
        return (ContentLanguage::tryFrom($code) ?? ContentLanguage::DEFAULT)->englishName();
    }

    /**
     * @return array{
     *   is_defined: bool,
     *   brand_color_name: ?string,
     *   background_color_name: ?string,
     *   text_color_name: ?string
     * }
     */
    private function buildPaletteContext(
        ?string $brandColor,
        ?string $backgroundColor,
        ?string $textColor,
    ): array {
        $brandColorName = $this->resolveColorName($brandColor);
        $backgroundColorName = $this->resolveColorName($backgroundColor);
        $textColorName = $this->resolveColorName($textColor);

        return [
            'is_defined' => $brandColorName !== null || $backgroundColorName !== null || $textColorName !== null,
            'brand_color_name' => $brandColorName,
            'background_color_name' => $backgroundColorName,
            'text_color_name' => $textColorName,
        ];
    }

    private function resolveColorName(?string $hex): ?string
    {
        if ($hex === null || trim($hex) === '') {
            return null;
        }

        return HexColorName::approximate($hex);
    }
}
