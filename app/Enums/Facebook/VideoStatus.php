<?php

declare(strict_types=1);

namespace App\Enums\Facebook;

/**
 * `status.video_status` of a video uploaded to a Facebook Page.
 *
 * @see https://developers.facebook.com/docs/graph-api/reference/video/
 */
enum VideoStatus: string
{
    case UploadComplete = 'upload_complete';
    case Processing = 'processing';
    case Ready = 'ready';
    case Error = 'error';
    case Expired = 'expired';

    /** A video Facebook will never finish processing. */
    public function hasFailed(): bool
    {
        return collect([self::Error, self::Expired])->containsStrict($this);
    }

    /** Facebook has the whole file: it is processing it or already done. */
    public function isUploaded(): bool
    {
        return collect([self::UploadComplete, self::Ready])->containsStrict($this);
    }
}
