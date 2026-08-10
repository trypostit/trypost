<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\Workspace\ContentLanguage;
use App\Enums\Workspace\ImageStyle;
use App\Support\HexColorName;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Image;
use Laravel\Ai\Responses\ImageResponse;
use Throwable;

class AiImageClient
{
    private const BRAND_DESCRIPTION_MAX = 200;

    /**
     * Generate an image via the configured AI_IMAGE_PROVIDER (defaults to OpenAI).
     * Returns null on any failure so the caller can fall back to a stock photo
     * without throwing.
     *
     * @param  array<int, string>  $keywords
     * @return array{bytes: string, provider: string, model: string}|null
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
    ): ?array {
        $keywords = $this->cleanKeywords($keywords);

        if ($keywords === []) {
            return null;
        }

        $prompt = $this->buildPrompt($keywords, $style, $language, $brandColor, $backgroundColor, $textColor, $brandDescription);

        try {
            $builder = Image::of($prompt)->quality($quality)->timeout($timeout);

            $builder = match ($orientation) {
                'portrait' => $builder->portrait(),
                'landscape' => $builder->landscape(),
                default => $builder->square(),
            };

            return $this->toResult($builder->generate());
        } catch (Throwable $e) {
            Log::warning('AiImageClient: generation failed', [
                'style' => $style->value,
                'orientation' => $orientation,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<int, string>  $keywords
     * @return array<int, string>
     */
    private function cleanKeywords(array $keywords): array
    {
        return collect($keywords)
            ->map(fn (string $keyword) => trim($keyword))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function buildPrompt(
        array $keywords,
        ImageStyle $style,
        string $language,
        ?string $brandColor,
        ?string $backgroundColor,
        ?string $textColor,
        ?string $brandDescription,
    ): string {
        $palette = $this->buildPaletteContext($brandColor, $backgroundColor, $textColor);

        return view('prompts.post_image.generator', [
            'style' => $style->value,
            'scene' => implode(', ', $keywords),
            'language_name' => $this->languageName($language),
            'has_brand_palette' => data_get($palette, 'is_defined', false),
            'brand_color_name' => data_get($palette, 'brand_color_name'),
            'background_color_name' => data_get($palette, 'background_color_name'),
            'text_color_name' => data_get($palette, 'text_color_name'),
            'brand_context' => $this->resolveBrandContext($brandDescription),
        ])->render();
    }

    private function resolveBrandContext(?string $brandDescription): ?string
    {
        $trimmed = trim((string) $brandDescription);

        if ($trimmed === '') {
            return null;
        }

        return mb_strlen($trimmed) > self::BRAND_DESCRIPTION_MAX
            ? mb_substr($trimmed, 0, self::BRAND_DESCRIPTION_MAX).'…'
            : $trimmed;
    }

    /**
     * Extract the raw image bytes and the provider/model that produced them.
     * Called from inside generate()'s try block so a malformed response
     * (e.g. no images) is treated as a failure, not an uncaught exception.
     *
     * @return array{bytes: string, provider: string, model: string}|null
     */
    private function toResult(ImageResponse $response): ?array
    {
        $bytes = (string) $response;

        if ($bytes === '') {
            return null;
        }

        return [
            'bytes' => $bytes,
            'provider' => (string) $response->meta->provider,
            'model' => (string) $response->meta->model,
        ];
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
