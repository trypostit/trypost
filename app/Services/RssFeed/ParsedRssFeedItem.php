<?php

declare(strict_types=1);

namespace App\Services\RssFeed;

use Carbon\CarbonImmutable;

final readonly class ParsedRssFeedItem
{
    public function __construct(
        public ?string $guid,
        public string $title,
        public ?string $url,
        public ?string $excerpt,
        public ?string $imageUrl,
        public ?string $author,
        public ?CarbonImmutable $publishedAt,
    ) {}
}
