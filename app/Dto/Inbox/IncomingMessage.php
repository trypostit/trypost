<?php

declare(strict_types=1);

namespace App\Dto\Inbox;

use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReviewReplyState;
use Carbon\CarbonImmutable;

final readonly class IncomingMessage
{
    /**
     * @param  list<array{type: string, url: string, thumbnail_url?: string|null}>  $media
     * @param  array<string, mixed>  $meta
     * @param  CarbonImmutable|null  $observedAt  when the network reported this state (a webhook's event time); null is a read made now
     * @param  bool|null  $hidden  whether the channel hid the comment on the network; null when the read does not say
     */
    public function __construct(
        public ConversationKind $kind,
        public IncomingSubject $subject,
        public string $platformMessageId,
        public ?string $platformParentId,
        public MessageDirection $direction,
        public ?string $authorPlatformId,
        public ?string $authorName,
        public ?string $authorUsername,
        public ?string $authorAvatarUrl,
        public ?string $body,
        public array $media,
        public ?string $platformUrl,
        public CarbonImmutable $platformCreatedAt,
        public array $meta = [],
        public ?int $rating = null,
        public bool $removed = false,
        public ?ReviewReplyState $reviewReplyState = null,
        public ?CarbonImmutable $observedAt = null,
        public ?bool $hidden = null,
    ) {}

    public function withObservedAt(CarbonImmutable $observedAt): self
    {
        return new self(...[...get_object_vars($this), 'observedAt' => $observedAt]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'subject' => $this->subject->toArray(),
            'platform_message_id' => $this->platformMessageId,
            'platform_parent_id' => $this->platformParentId,
            'direction' => $this->direction->value,
            'author_platform_id' => $this->authorPlatformId,
            'author_name' => $this->authorName,
            'author_username' => $this->authorUsername,
            'author_avatar_url' => $this->authorAvatarUrl,
            'body' => $this->body,
            'media' => $this->media,
            'platform_url' => $this->platformUrl,
            'platform_created_at' => $this->platformCreatedAt->toIso8601String(),
            'meta' => $this->meta,
            'rating' => $this->rating,
            'removed' => $this->removed,
            'review_reply_state' => $this->reviewReplyState?->value,
            'observed_at' => $this->observedAt?->toIso8601String(),
            'hidden' => $this->hidden,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            kind: ConversationKind::from((string) data_get($data, 'kind')),
            subject: IncomingSubject::fromArray((array) data_get($data, 'subject', [])),
            platformMessageId: (string) data_get($data, 'platform_message_id'),
            platformParentId: data_get($data, 'platform_parent_id'),
            direction: MessageDirection::from((string) data_get($data, 'direction')),
            authorPlatformId: data_get($data, 'author_platform_id'),
            authorName: data_get($data, 'author_name'),
            authorUsername: data_get($data, 'author_username'),
            authorAvatarUrl: data_get($data, 'author_avatar_url'),
            body: data_get($data, 'body'),
            media: array_values((array) data_get($data, 'media', [])),
            platformUrl: data_get($data, 'platform_url'),
            platformCreatedAt: CarbonImmutable::parse((string) data_get($data, 'platform_created_at')),
            meta: (array) data_get($data, 'meta', []),
            rating: transform(data_get($data, 'rating'), fn (mixed $rating): int => (int) $rating),
            removed: (bool) data_get($data, 'removed', false),
            reviewReplyState: transform(data_get($data, 'review_reply_state'), fn (string $state): ReviewReplyState => ReviewReplyState::from($state)),
            observedAt: transform(data_get($data, 'observed_at'), fn (string $at): CarbonImmutable => CarbonImmutable::parse($at)),
            hidden: transform(data_get($data, 'hidden'), fn (mixed $hidden): bool => (bool) $hidden),
        );
    }
}
