<?php

declare(strict_types=1);

namespace App\Dto\Inbox;

/**
 * What a Meta direct message carries besides its text: media shown in the
 * bubble, other content (`meta.content`) and the reactions it received
 * (`meta.reactions`).
 */
final readonly class MetaMessageParts
{
    /**
     * @param  list<array{type: string, url: string, thumbnail_url?: string}>  $media
     * @param  array<string, string>|null  $content
     * @param  list<array{emoji: string, user_id: string}>  $reactions
     */
    public function __construct(
        public array $media = [],
        public ?array $content = null,
        public array $reactions = [],
    ) {}

    public function isEmpty(?string $text): bool
    {
        return blank($text) && $this->media === [] && $this->content === null;
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        return array_filter(['content' => $this->content, 'reactions' => $this->reactions], fn (mixed $value): bool => filled($value));
    }
}
