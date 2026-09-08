<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enums\Repurpose\SourceFormat;
use Carbon\CarbonInterface;

readonly class SourceMedia
{
    public function __construct(
        public string $id,
        public ?SourceFormat $format,
        public ?string $downloadUrl,
        public string $caption,
        public ?string $permalink,
        public ?CarbonInterface $createdAt,
    ) {}

    public function predates(?CarbonInterface $watermark): bool
    {
        return $watermark !== null
            && $this->createdAt !== null
            && $this->createdAt->lessThanOrEqualTo($watermark);
    }
}
