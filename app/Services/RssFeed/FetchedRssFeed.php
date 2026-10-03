<?php

declare(strict_types=1);

namespace App\Services\RssFeed;

final readonly class FetchedRssFeed
{
    public function __construct(
        public string $url,
        public ParsedRssFeed $feed,
    ) {}
}
