<?php

declare(strict_types=1);

namespace App\Enums\RssFeed;

enum Format: string
{
    case Rss = 'rss';
    case Atom = 'atom';
    case JsonFeed = 'json_feed';
}
