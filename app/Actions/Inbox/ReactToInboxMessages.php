<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Dto\Inbox\InboxReaction;
use App\Events\InboxUpdated;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;

class ReactToInboxMessages
{
    /**
     * Records each person's reaction on the stored message it is about
     * (`meta.reactions`), one per person: a new reaction replaces theirs and
     * an unreact removes it. A reaction to a message that is not stored is
     * dropped.
     *
     * @param  list<InboxReaction>  $reactions
     */
    public static function execute(SocialAccount $account, array $reactions): void
    {
        foreach ($reactions as $reaction) {
            $message = InboxMessage::query()
                ->where('social_account_id', $account->id)
                ->where('platform_message_hash', InboxConversation::hashOf($reaction->platformMessageId))
                ->first();

            if (! $message) {
                continue;
            }

            $kept = collect((array) data_get($message->meta, 'reactions', []))
                ->reject(fn (mixed $stored): bool => (string) data_get($stored, 'user_id') === $reaction->userId);
            $updated = filled($reaction->emoji)
                ? $kept->push(['emoji' => $reaction->emoji, 'user_id' => $reaction->userId])
                : $kept;

            $meta = array_filter([...(array) $message->meta, 'reactions' => $updated->values()->all()], fn (mixed $value): bool => filled($value));
            $message->forceFill(['meta' => $meta]);

            if ($message->isDirty('meta')) {
                $message->save();
                InboxUpdated::dispatch($account->workspace_id, $message->inbox_conversation_id);
            }
        }
    }
}
