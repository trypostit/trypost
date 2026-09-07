<?php

declare(strict_types=1);

namespace App\Support\Repurpose;

use App\Enums\SocialAccount\Platform;

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
}
