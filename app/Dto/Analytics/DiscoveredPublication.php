<?php

declare(strict_types=1);

namespace App\Dto\Analytics;

use App\Enums\Analytics\PublicationContentType;
use Carbon\CarbonImmutable;

final readonly class DiscoveredPublication
{
    /**
     * @param  bool  $contentTypeReported  False when the network does not report the type, so $contentType only fills a new publication.
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
        public bool $contentTypeReported = true,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider_post_id' => $this->providerPostId,
            'published_at' => $this->publishedAt->toIso8601String(),
            'content_type' => $this->contentType->value,
            'provider_content_type' => $this->providerContentType,
            'permalink' => $this->permalink,
            'excerpt' => $this->excerpt,
            'preview_metadata' => $this->previewMetadata,
            'provider_metadata' => $this->providerMetadata,
            'content_type_reported' => $this->contentTypeReported,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            providerPostId: (string) data_get($data, 'provider_post_id'),
            publishedAt: CarbonImmutable::parse((string) data_get($data, 'published_at')),
            contentType: PublicationContentType::tryFrom((string) data_get($data, 'content_type')) ?? PublicationContentType::Unknown,
            providerContentType: data_get($data, 'provider_content_type'),
            permalink: data_get($data, 'permalink'),
            excerpt: data_get($data, 'excerpt'),
            previewMetadata: data_get($data, 'preview_metadata'),
            providerMetadata: data_get($data, 'provider_metadata'),
            contentTypeReported: (bool) data_get($data, 'content_type_reported', true),
        );
    }
}
