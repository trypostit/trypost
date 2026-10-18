<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Enums\Inbox\MessageDirection;
use App\Events\InboxUpdated;
use App\Models\InboxMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reopens a resolved message with its thread; in a direct message
 * conversation, which has no threads, the whole conversation reopens.
 */
class UnresolveInboxMessage
{
    public static function execute(InboxMessage $message): InboxMessage
    {
        $rootId = $message->root_id ?? $message->id;
        $conversation = $message->conversation;

        DB::transaction(function () use ($message, $rootId, $conversation): void {
            InboxMessage::query()
                ->where('inbox_conversation_id', $message->inbox_conversation_id)
                ->where('direction', MessageDirection::Inbound->value)
                ->whereNotNull('resolved_at')
                ->when(! $conversation->isFlat(), fn (Builder $query) => $query->where(fn (Builder $tree) => $tree->where('id', $rootId)->orWhere('root_id', $rootId)))
                ->update(['resolved_at' => null, 'resolved_by_user_id' => null]);

            RecountInboxConversation::execute($conversation);
        });

        InboxUpdated::dispatch($conversation->workspace_id, $conversation->id);

        return $message->refresh();
    }
}
