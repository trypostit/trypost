<?php

declare(strict_types=1);

namespace App\Support\Repurpose;

use App\Enums\SocialAccount\Platform;

/**
 * Ready-made starting points offered in the UI and over the API/MCP.
 *
 * These are presets, not rows: adding a network later is a new entry here plus
 * a fetcher, with no migration. Labels live in the `repurposes` translations,
 * keyed by `key`, so they are never hardcoded in PHP.
 */
class Templates
{
    /**
     * @return array<int, array{key: string, source_platform: string, destination_platforms: array<int, string>}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'instagram_everywhere',
                'source_platform' => Platform::Instagram->value,
                'destination_platforms' => [
                    Platform::TikTok->value,
                    Platform::YouTube->value,
                    Platform::Facebook->value,
                ],
            ],
            [
                'key' => 'facebook_everywhere',
                'source_platform' => Platform::Facebook->value,
                'destination_platforms' => [
                    Platform::Instagram->value,
                    Platform::TikTok->value,
                    Platform::YouTube->value,
                ],
            ],
        ];
    }

    public static function find(?string $key): ?array
    {
        return collect(self::all())->firstWhere('key', $key);
    }
}
