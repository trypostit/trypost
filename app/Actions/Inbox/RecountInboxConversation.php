<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Enums\Inbox\MessageDirection;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use Illuminate\Support\Facades\DB;

class RecountInboxConversation
{
    public static function execute(InboxConversation $conversation): void
    {
        DB::transaction(function () use ($conversation): void {
            InboxConversation::query()->whereKey($conversation->id)->lockForUpdate()->first();

            $messages = InboxMessage::query()->where('inbox_conversation_id', $conversation->id);
            $inbound = (clone $messages)->where('direction', MessageDirection::Inbound->value);
            $lastInbound = (clone $inbound)->max('platform_created_at');
            $conversation->last_inbound_at = $lastInbound;

            $conversation->forceFill([
                'open_count' => (clone $messages)->open()->count(),
                'message_count' => (clone $messages)->whereNull('removed_at')->count(),
                'inbound_count' => (clone $inbound)->whereNull('removed_at')->count(),
                'last_message_at' => (clone $messages)->max('platform_created_at'),
                'last_inbound_at' => $lastInbound,
                'reply_window_ends_at' => $conversation->liveReplyWindowEndsAt(),
            ])->save();
        });
    }
}
