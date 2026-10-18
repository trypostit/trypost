<?php

declare(strict_types=1);

namespace App\Enums\Inbox;

/**
 * What a direct message carries besides its text and media, stored in
 * `inbox_messages.meta.content` so the bubble and the list can name it.
 */
enum DirectMessageContent: string
{
    case SharedPost = 'shared_post';
    case StoryReply = 'story_reply';
    case StoryMention = 'story_mention';
    case Attachment = 'attachment';
    case Unsupported = 'unsupported';

    /**
     * @param  array{url?: string|null, title?: string|null, thumbnail_url?: string|null, thumbnail_type?: string|null}  $fields
     * @return array{type: string, url?: string, title?: string, thumbnail_url?: string, thumbnail_type?: string}
     */
    public function toMeta(array $fields = []): array
    {
        return array_filter(['type' => $this->value, ...$fields], fn (mixed $value): bool => filled($value));
    }
}
