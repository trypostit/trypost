<?php

declare(strict_types=1);

namespace App\Enums\YouTube;

/**
 * `status.privacyStatus` on videos.insert.
 *
 * @see https://developers.google.com/youtube/v3/docs/videos#status.privacyStatus
 */
enum PrivacyStatus: string
{
    case Public = 'public';
    case Unlisted = 'unlisted';
    case Private = 'private';

    public const DEFAULT = self::Public;
}
