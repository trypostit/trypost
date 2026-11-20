<?php

declare(strict_types=1);

namespace App\Enums\YouTube;

/**
 * Why YouTube did not set the cover image of a video that is already live
 * as its thumbnail (`thumbnails.set`), kept in
 * `posts.error_context.youtube_thumbnail_warning`. The post stays published.
 *
 * @see https://developers.google.com/youtube/v3/docs/thumbnails/set#errors
 */
enum ThumbnailWarning: string
{
    case Forbidden = 'forbidden';
    case DailyLimit = 'daily_limit';
    case InvalidImage = 'invalid_image';
    case Failed = 'failed';
}
