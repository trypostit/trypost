<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Enums\Inbox\MessageDirection;
use App\Models\InboxMessage;
use Illuminate\Database\Eloquent\Builder;

class MarkInboxTreeAnswered
{
    public static function execute(InboxMessage $outbound): void
    {
        $isFlat = (bool) $outbound->loadMissing('conversation')->conversation?->isFlat();
        $rootId = $outbound->root_id ?? $outbound->parent_id;

        if ((! $isFlat && blank($rootId)) || filled($outbound->removed_at)) {
            return;
        }

        InboxMessage::query()
            ->where('inbox_conversation_id', $outbound->inbox_conversation_id)
            ->where('direction', MessageDirection::Inbound->value)
            ->whereNull('answered_at')
            ->when(! $isFlat, fn (Builder $query) => $query->where(fn (Builder $tree) => $tree->where('id', $rootId)->orWhere('root_id', $rootId)))
            ->where('platform_created_at', '<=', $outbound->platform_created_at)
            ->update(['answered_at' => $outbound->platform_created_at]);
    }
}
