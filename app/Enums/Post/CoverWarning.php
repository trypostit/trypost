<?php

declare(strict_types=1);

namespace App\Enums\Post;

/**
 * Why a network did not apply the cover image of a post that is already
 * live, kept in `posts.error_context.cover_warning`. The post stays published.
 */
enum CoverWarning: string
{
    case Forbidden = 'forbidden';
    case DailyLimit = 'daily_limit';
    case InvalidImage = 'invalid_image';
    case Failed = 'failed';
}
