<?php

declare(strict_types=1);

namespace App\Dto\Analytics;

use App\Enums\Analytics\PublicationContentType;
use Carbon\CarbonImmutable;

final readonly class DiscoveredPublication
{
    /**
     * @param  array<string, mixed>|null  $previewMetadata
     * @param  array<string, mixed>|null  $providerMetadata
     */
    public function __construct(
        public string $providerPostId,
        public CarbonImmutable $publishedAt,
        public PublicationContentType $contentType,
        public ?string $providerContentType = null,
        public ?string $permalink = null,
        public ?string $excerpt = null,
        public ?array $previewMetadata = null,
        public ?array $providerMetadata = null,
    ) {}
}
