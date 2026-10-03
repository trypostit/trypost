<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\YouTube\Category;
use Illuminate\Support\Str;

/**
 * YouTube upload metadata other than the description: title and
 * category. The explicit title wins; without one the title is the first
 * non-empty line of the post's plain text, the same rule the composer uses
 * to fill the Title field.
 */
class YouTubeMetadata
{
    public const int TITLE_MAX_LENGTH = 100;

    /**
     * @param  array<string, mixed>|null  $meta
     * @param  string  $content  the post text after ContentSanitizer, as publishers and previews pass it
     */
    public static function title(?array $meta, string $content): string
    {
        $title = data_get($meta, 'title');

        if (is_string($title) && filled(Str::trim($title))) {
            return Str::trim($title);
        }

        $firstLine = collect(explode("\n", $content))
            ->map(fn (string $line): string => Str::trim(str_replace(['<', '>'], '', $line)))
            ->first(fn (string $line): bool => $line !== '', '');

        return mb_substr($firstLine, 0, self::TITLE_MAX_LENGTH);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function categoryId(?array $meta): string
    {
        return (Category::tryFrom((string) data_get($meta, 'category_id')) ?? Category::DEFAULT)->value;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function violation(mixed $meta): ?array
    {
        $title = data_get($meta, 'title');

        if (is_string($title) && (str_contains($title, '<') || str_contains($title, '>'))) {
            return ['title', __('posts.form.youtube.title_invalid')];
        }

        return null;
    }
}
