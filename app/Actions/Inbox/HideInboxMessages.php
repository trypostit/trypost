<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Events\InboxUpdated;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Builder;

class HideInboxMessages
{
    /**
     * Records that the channel hid comments on the network (or showed them
     * again). A hidden comment is still there for its author and the channel,
     * so it stays listed and open; only its state changes.
     *
     * @param  list<string>  $platformMessageIds
     */
    public static function execute(SocialAccount $account, array $platformMessageIds, bool $hidden): int
    {
        if ($platformMessageIds === []) {
            return 0;
        }

        $messages = InboxMessage::query()
            ->where('social_account_id', $account->id)
            ->whereIn('platform_message_hash', array_map(InboxConversation::hashOf(...), array_values(array_unique($platformMessageIds))))
            ->when($hidden, fn (Builder $query) => $query->whereNull('hidden_at'), fn (Builder $query) => $query->whereNotNull('hidden_at'))
            ->get(['id', 'inbox_conversation_id']);

        if ($messages->isEmpty()) {
            return 0;
        }

        InboxMessage::query()->whereKey($messages->pluck('id'))->update(['hidden_at' => $hidden ? now() : null]);

        $messages->pluck('inbox_conversation_id')->unique()
            ->each(fn (string $conversationId) => InboxUpdated::dispatch($account->workspace_id, $conversationId));

        return $messages->count();
    }
}
