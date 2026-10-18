<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Enums\Inbox\MessageDirection;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use Illuminate\Database\Eloquent\Builder;

class ListConversationThreads
{
    public const string PAGE_NAME = 'thread_page';

    /**
     * The conversation's top-level messages, newest first, each with its whole
     * reply tree, leaving out what the network removed, so a post with thousands of comments loads page by page.
     *
     * @return Builder<InboxMessage>
     */
    public static function execute(InboxConversation $conversation): Builder
    {
        return InboxMessage::query()
            ->where('inbox_conversation_id', $conversation->id)
            ->whereNull('parent_id')
            ->notRemoved()
            ->with([
                'sentBy',
                'treeMessages' => fn ($tree) => $tree->notRemoved()->orderBy('platform_created_at')->orderBy('id'),
                'treeMessages.sentBy',
            ])
            ->orderByDesc('platform_created_at')
            ->orderByDesc('id');
    }

    /**
     * The page that holds the thread of this message, or null when the
     * message is not in the conversation.
     */
    public static function pageOf(InboxConversation $conversation, string $messageId, int $perPage): ?int
    {
        $message = InboxMessage::query()->where('inbox_conversation_id', $conversation->id)->find($messageId);
        $root = $message && filled($message->root_id) ? InboxMessage::query()->find($message->root_id) : $message;

        if (! $root) {
            return null;
        }

        $newer = InboxMessage::query()
            ->where('inbox_conversation_id', $conversation->id)
            ->whereNull('parent_id')
            ->notRemoved()
            ->where(fn (Builder $query) => $query
                ->where('platform_created_at', '>', $root->platform_created_at)
                ->orWhere(fn (Builder $same) => $same->where('platform_created_at', $root->platform_created_at)->where('id', '>', $root->id)))
            ->count();

        return intdiv($newer, max(1, $perPage)) + 1;
    }

    public static function repliedCount(InboxConversation $conversation): int
    {
        return InboxMessage::query()
            ->where('inbox_conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Inbound->value)
            ->whereNotNull('answered_at')
            ->whereNull('removed_at')
            ->count();
    }
}
