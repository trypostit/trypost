<?php

declare(strict_types=1);

namespace App\Enums\Instagram;

/**
 * `media_product_type` values on an IG Media node — the surface the media was
 * published to. Meta documents it as readable by the Facebook-login API only,
 * so a standalone Instagram account may not return it at all.
 *
 * @see https://developers.facebook.com/docs/instagram-platform/reference/instagram-media/
 */
enum MediaProductType: string
{
    case Ad = 'AD';
    case Feed = 'FEED';
    case Reels = 'REELS';
    case Story = 'STORY';
}
