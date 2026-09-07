<?php

declare(strict_types=1);

namespace App\Enums\Facebook;

/**
 * @see https://developers.facebook.com/docs/page-stories-api/
 */
enum StoryStatus: string
{
    case Published = 'PUBLISHED';
    case Archived = 'ARCHIVED';
}
