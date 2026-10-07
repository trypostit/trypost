<?php

declare(strict_types=1);

namespace App\Enums\X;

/**
 * Media upload `processing_info.state` values.
 *
 * @see https://docs.x.com/x-api/media/media-upload-status
 */
enum MediaProcessingState: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isProcessing(): bool
    {
        return $this === self::Pending || $this === self::InProgress;
    }
}
