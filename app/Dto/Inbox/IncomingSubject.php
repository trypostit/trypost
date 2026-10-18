<?php

declare(strict_types=1);

namespace App\Dto\Inbox;

use App\Dto\Analytics\DiscoveredPublication;
use Carbon\CarbonImmutable;

final readonly class IncomingSubject
{
    /**
     * @param  list<array{type: string, url: string, thumbnail_url?: string|null}>  $media
     * @param  array<string, mixed>  $meta
     * @param  DiscoveredPublication|null  $publication  the channel's publication the comments are under, when the poll listed it
     */
    public function __construct(
        public string $platformId,
        public ?string $text,
        public array $media,
        public ?string $url,
        public ?CarbonImmutable $publishedAt,
        public ?string $remoteId,
        public array $meta = [],
        public ?string $participantName = null,
        public ?string $participantUsername = null,
        public ?string $participantAvatarUrl = null,
        public ?DiscoveredPublication $publication = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'platform_id' => $this->platformId,
            'text' => $this->text,
            'media' => $this->media,
            'url' => $this->url,
            'published_at' => $this->publishedAt?->toIso8601String(),
            'remote_id' => $this->remoteId,
            'meta' => $this->meta,
            'participant_name' => $this->participantName,
            'participant_username' => $this->participantUsername,
            'participant_avatar_url' => $this->participantAvatarUrl,
            'publication' => $this->publication?->toArray(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            platformId: (string) data_get($data, 'platform_id'),
            text: data_get($data, 'text'),
            media: array_values((array) data_get($data, 'media', [])),
            url: data_get($data, 'url'),
            publishedAt: transform(data_get($data, 'published_at'), fn (string $at): CarbonImmutable => CarbonImmutable::parse($at)),
            remoteId: data_get($data, 'remote_id'),
            meta: (array) data_get($data, 'meta', []),
            participantName: data_get($data, 'participant_name'),
            participantUsername: data_get($data, 'participant_username'),
            participantAvatarUrl: data_get($data, 'participant_avatar_url'),
            publication: transform(data_get($data, 'publication'), fn (array $publication): DiscoveredPublication => DiscoveredPublication::fromArray($publication)),
        );
    }
}
