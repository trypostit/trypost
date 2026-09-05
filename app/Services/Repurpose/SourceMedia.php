<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

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

    public function isVideo(): bool
    {
        return $this->format !== null;
    }
}
