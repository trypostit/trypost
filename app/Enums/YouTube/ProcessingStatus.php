<?php

declare(strict_types=1);

namespace App\Enums\YouTube;

/**
 * `processingDetails.processingStatus` of a video YouTube already has.
 *
 * @see https://developers.google.com/youtube/v3/docs/videos#processingDetails.processingStatus
 */
enum ProcessingStatus: string
{
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Terminated = 'terminated';
}
