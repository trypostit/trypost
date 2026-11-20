<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

/**
 * The `provider_metadata` keys under which Facebook discovery stores the video
 * or photo a Page post carries, which TryPost may have saved as the post's id.
 */
enum FacebookMediaKey: string
{
    case Video = 'video_id';
    case Photo = 'photo_id';
}
