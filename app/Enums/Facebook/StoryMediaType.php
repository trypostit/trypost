<?php

declare(strict_types=1);

namespace App\Enums\Facebook;

/**
 * `media_type` values on GET /{page-id}/stories.
 *
 * @see https://developers.facebook.com/docs/page-stories-api/
 */
enum StoryMediaType: string
{
    case Video = 'video';
    case Photo = 'photo';
}
