<?php

declare(strict_types=1);

namespace App\Enums\Repurpose;

enum ItemReason: string
{
    case PublishedViaTrypost = 'published_via_trypost';
    case MediaUrlMissing = 'media_url_missing';
    case DownloadFailed = 'download_failed';
    case PostCreationFailed = 'post_creation_failed';
    case NoUsableDestinations = 'no_usable_destinations';
}
