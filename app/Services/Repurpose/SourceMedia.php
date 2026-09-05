<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use Carbon\CarbonInterface;

readonly class SourceMedia
{
    public function __construct(
        public string $id,
        public bool $isVideo,
        public ?string $downloadUrl,
        public string $caption,
        public ?string $permalink,
        public ?CarbonInterface $createdAt,
    ) {}
}
