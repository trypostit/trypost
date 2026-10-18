<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Dto\Inbox\IncomingMessage;
use App\Enums\Inbox\ConversationKind;
use App\Events\InboxUpdated;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The one way network reads (polls and webhooks) reach the inbox. A comment
 * always belongs to a post in TryPost: one whose post is not there yet waits
 * (ParkInboxMessages) until its post is imported. Mentions, reviews and
 * direct messages have no post and are stored at once.
 */
class UpsertInboxMessages
{
    /**
     * A message read without its id (or its subject's) cannot be told apart
     * from another one, so it is skipped instead of merged into it.
     *
     * @param  list<IncomingMessage>  $messages
     * @return Collection<int, string>
     */
    public static function execute(SocialAccount $account, array $messages, bool $broadcast = true): Collection
    {
        $known = [];

        [$waiting, $ready] = collect($messages)
            ->filter(fn (IncomingMessage $message): bool => filled($message->platformMessageId) && filled($message->subject->platformId))
            ->partition(function (IncomingMessage $message) use ($account, &$known): bool {
                return $message->kind === ConversationKind::Comment
                    && ! ($known[$message->subject->platformId] ??= self::postIsStored($account, $message))
                    && ! self::parentHasPost($account, $message);
            });

        if ($waiting->isNotEmpty()) {
            ParkInboxMessages::execute($account, $waiting->values()->all());
        }

        $touched = $ready
            ->sortBy(fn (IncomingMessage $message): int => $message->platformCreatedAt->getTimestamp())
            ->map(fn (IncomingMessage $message): InboxMessage => UpsertInboxMessage::execute($account, $message, broadcast: false))
            ->filter(fn (InboxMessage $message): bool => $message->wasRecentlyCreated || $message->wasChanged() || $message->leftConversationIds !== [])
            ->flatMap(fn (InboxMessage $message): array => [$message->inbox_conversation_id, ...$message->leftConversationIds])
            ->unique()
            ->values();

        if ($broadcast) {
            $touched->each(fn (string $conversationId) => InboxUpdated::dispatch($account->workspace_id, $conversationId));
        }

        return $touched;
    }

    private static function postIsStored(SocialAccount $account, IncomingMessage $message): bool
    {
        return InboxConversation::query()
            ->where('social_account_id', $account->id)
            ->where('kind', ConversationKind::Comment->value)
            ->where('platform_conversation_hash', InboxConversation::hashOf($message->subject->platformId))
            ->whereNotNull('post_id')
            ->exists()
            || filled(LinkInboxConversationPost::forSubject($account, $message));
    }

    private static function parentHasPost(SocialAccount $account, IncomingMessage $message): bool
    {
        return filled($message->platformParentId) && InboxMessage::query()
            ->where('social_account_id', $account->id)
            ->where('platform_message_hash', InboxConversation::hashOf($message->platformParentId))
            ->whereHas('conversation', fn (Builder $conversation) => $conversation->whereNotNull('post_id'))
            ->exists();
    }
}
