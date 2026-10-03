<?php

declare(strict_types=1);

namespace App\Enums\YouTube;

/**
 * `status.license` on videos.insert.
 *
 * @see https://developers.google.com/youtube/v3/docs/videos#status.license
 */
enum License: string
{
    case YouTube = 'youtube';
    case CreativeCommon = 'creativeCommon';

    public const DEFAULT = self::YouTube;
}
