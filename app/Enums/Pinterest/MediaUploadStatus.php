<?php

declare(strict_types=1);

namespace App\Enums\Pinterest;

/**
 * Pinterest media upload lifecycle statuses from GET /v5/media/{media_id}.
 *
 * @see https://developers.pinterest.com/docs/api/v5/media-get/
 */
enum MediaUploadStatus: string
{
    case Registered = 'registered';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
