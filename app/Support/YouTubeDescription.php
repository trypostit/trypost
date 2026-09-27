<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

class YouTubeDescription
{
    public const int MAX_BYTES = 5000;

    public static function violation(mixed $description): ?string
    {
        if ($description === null) {
            return null;
        }

        return match (true) {
            ! is_string($description),
            ! mb_check_encoding($description, 'UTF-8') => 'posts.form.youtube.description_invalid',
            strlen($description) > self::MAX_BYTES => 'posts.form.youtube.description_max',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function resolve(?array $meta, ?string $content): string
    {
        $description = data_get($meta, 'description');

        return is_string($description) && filled(Str::trim($description))
            ? $description
            : ($content ?? '');
    }
}
