<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Dto\Inbox\IncomingMessage;
use App\Jobs\Inbox\ImportInboxPost;
use App\Models\InboxConversation;
use App\Models\InboxPendingMessage;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

/**
 * Keeps comments read before their post is in TryPost, one row per comment
 * (a webhook, a poll and Meta's retries of the same comment share it, the
 * latest state wins), and queues the import of each post they are under.
 */
class ParkInboxMessages
{
    /** @param list<IncomingMessage> $messages */
    public static function execute(SocialAccount $account, array $messages): void
    {
        $posts = [];

        foreach ($messages as $message) {
            $postId = LinkInboxConversationPost::remoteIdOf($account, $message->subject);
            $observedAt = $message->observedAt ?? CarbonImmutable::now();
            $identity = ['social_account_id' => $account->id, 'platform_message_hash' => InboxConversation::hashOf($message->platformMessageId)];
            $values = [
                'platform_post_id' => $postId,
                'platform_post_hash' => InboxConversation::hashOf($postId),
                'payload' => $message->toArray(),
                'observed_at' => $observedAt,
            ];

            $pending = InboxPendingMessage::query()->createOrFirst($identity, $values);

            if (! $pending->wasRecentlyCreated && ($pending->observed_at === null || $pending->observed_at->lte($observedAt))) {
                $pending->fill($values)->save();
            }

            $posts[$postId] = true;
        }

        foreach (array_keys($posts) as $postId) {
            ImportInboxPost::dispatch($account->id, (string) $postId);
        }
    }
}
