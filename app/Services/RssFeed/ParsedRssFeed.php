<?php

declare(strict_types=1);

namespace App\Services\RssFeed;

use App\Enums\RssFeed\Format;

final readonly class ParsedRssFeed
{
    /**
     * @param  list<ParsedRssFeedItem>  $items
     */
    public function __construct(
        public Format $format,
        public string $title,
        public ?string $siteUrl,
        public array $items,
    ) {}
}
