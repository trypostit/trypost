<?php

declare(strict_types=1);

namespace App\Enums\Instagram;

/**
 * @see https://developers.facebook.com/docs/instagram-platform/reference/instagram-media/
 */
enum MediaProductType: string
{
    case Ad = 'AD';
    case Feed = 'FEED';
    case Reels = 'REELS';
    case Story = 'STORY';
}
