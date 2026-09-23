<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

enum PublicationContentType: string
{
    case Text = 'text';
    case Image = 'image';
    case Carousel = 'carousel';
    case Video = 'video';
    case Reel = 'reel';
    case Story = 'story';
    case Short = 'short';
    case Link = 'link';
    case Poll = 'poll';
    case Unknown = 'unknown';
}
