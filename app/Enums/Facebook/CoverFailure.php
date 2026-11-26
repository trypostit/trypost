<?php

declare(strict_types=1);

namespace App\Enums\Facebook;

use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\SocialPublishException;

/**
 * Why a Facebook video kept Facebook's own cover instead of the image picked
 * for it, kept in `posts.error_context.facebook_cover_failure` next to the
 * card's warning, for whoever looks into it later. The post stays published.
 */
enum CoverFailure: string
{
    /** The channel was disconnected or its token expired before the cover went on. */
    case NotConnected = 'not_connected';

    /** The channel lacks the Reel cover's scopes. */
    case NoPermission = 'no_permission';

    /** Facebook refused the page token while the cover was being set. */
    case TokenExpired = 'token_expired';

    /** Facebook could not process the Reel, which then takes no cover. */
    case ProcessingFailed = 'processing_failed';

    /** Facebook was still processing the Reel after thirty minutes of checks. */
    case StillProcessing = 'still_processing';

    /** Facebook refused the cover or the request carrying it. */
    case Refused = 'refused';

    /** The cover image could not be downloaded. */
    case NotDownloaded = 'not_downloaded';

    /** The cover image could not be made a JPEG Facebook takes. */
    case InvalidImage = 'invalid_image';

    /** Every try failed for a reason a later try might have fixed. */
    case GaveUp = 'gave_up';

    /** Why the cover image file could not be sent: it did not download, or it is not an image. */
    public static function forCoverFile(SocialPublishException $exception): self
    {
        if ($exception->category === ErrorCategory::MediaFormat) {
            return self::InvalidImage;
        }

        return self::NotDownloaded;
    }
}
