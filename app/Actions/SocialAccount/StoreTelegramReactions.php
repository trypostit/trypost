<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\SocialAccount\Platform;
use App\Models\PostPlatform;

class StoreTelegramReactions
{
    /**
     * Persist the reaction counts pushed by a `message_reaction_count` update
     * onto the matching published post platform.
     *
     * @param  array<string, mixed>  $update  The `message_reaction_count` payload.
     */
    public static function execute(array $update): void
    {
        $chatId = (string) data_get($update, 'chat.id');
        $messageId = (string) data_get($update, 'message_id');

        if ($chatId === '' || $messageId === '') {
            return;
        }

        $postPlatform = PostPlatform::query()
            ->where('platform', Platform::Telegram->value)
            ->where('platform_post_id', $messageId)
            ->whereHas('socialAccount', fn ($query) => $query->where('meta->chat_id', $chatId))
            ->first();

        if ($postPlatform === null) {
            return;
        }

        $rawReactions = data_get($update, 'reactions');

        $reactions = array_values(array_map(fn (array $reaction): array => [
            'type' => (string) (data_get($reaction, 'type.emoji') ?? __('analytics.metrics.custom_reaction')),
            'count' => (int) data_get($reaction, 'total_count'),
        ], is_array($rawReactions) ? $rawReactions : []));

        $postPlatform->update(['meta' => [...$postPlatform->meta ?? [], 'reactions' => $reactions]]);
    }
}
