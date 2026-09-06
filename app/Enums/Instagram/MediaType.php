<?php

declare(strict_types=1);

namespace App\Enums\Instagram;

/**
 * `media_type` values on an IG Media node.
 *
 * @see https://developers.facebook.com/docs/instagram-platform/reference/instagram-media/
 */
enum MediaType: string
{
    case Image = 'IMAGE';
    case Video = 'VIDEO';
    case CarouselAlbum = 'CAROUSEL_ALBUM';
}
