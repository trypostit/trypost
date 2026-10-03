<?php

declare(strict_types=1);

namespace App\Services\Http;

final readonly class FetchedBody
{
    public function __construct(
        public string $body,
        public string $finalUrl,
    ) {}
}
