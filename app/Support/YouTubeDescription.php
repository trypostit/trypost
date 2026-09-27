<?php

declare(strict_types=1);

namespace App\Support;

class YouTubeDescription
{
    public const int MAX_BYTES = 5000;

    public static function violation(mixed $description): ?string
    {
        if ($description === null) {
            return null;
        }
        if (! is_string($description) || ! mb_check_encoding($description, 'UTF-8')) {
            return 'posts.form.youtube.description_invalid';
        }
        if (strlen($description) > self::MAX_BYTES) {
            return 'posts.form.youtube.description_max';
        }
        if (str_contains($description, '<') || str_contains($description, '>')) {
            return 'posts.form.youtube.description_invalid';
        }

        return null;
    }

    /** @param array<string, mixed>|null $meta */
    public static function resolve(?array $meta, ?string $content): string
    {
        $description = $meta['description'] ?? null;

        return is_string($description) && trim($description) !== '' ? $description : ($content ?? '');
    }
}
