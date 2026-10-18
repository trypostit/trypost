<?php

declare(strict_types=1);

namespace App\Services\Inbox\Concerns;

use App\Dto\Inbox\MessageLimit;
use App\Dto\Inbox\SentReply;
use App\Enums\Inbox\ConversationKind;
use App\Models\InboxConversation;
use App\Models\SocialAccount;
use App\Services\Inbox\Meta\MetaDirectMessages;
use Carbon\CarbonImmutable;

/**
 * The DirectMessageDriver half of a Meta inbox driver.
 */
trait SendsMetaDirectMessages
{
    public function sendDirectMessage(SocialAccount $account, InboxConversation $conversation, string $text): SentReply
    {
        return app(MetaDirectMessages::class)->forSending()->send($account, $conversation, $text);
    }

    public function directMessageText(InboxConversation $conversation, string $text): string
    {
        return $text;
    }

    public function directMessageLimit(SocialAccount $account): MessageLimit
    {
        return app(MetaDirectMessages::class)->limit($account);
    }

    public function hasReplyWindow(): bool
    {
        return true;
    }

    public function replyWindowEndsAt(?CarbonImmutable $lastInboundAt): ?CarbonImmutable
    {
        return app(MetaDirectMessages::class)->windowEndsAt($lastInboundAt);
    }

    /**
     * The webhook fields that deliver this channel's messages, while its
     * driver reads direct messages and the channel granted their scopes.
     *
     * @return list<string>
     */
    private function directMessageWebhookFields(SocialAccount $account, string ...$fields): array
    {
        return $account->platform->inboxKindEnabled(ConversationKind::DirectMessage) && $account->hasInboxAccessFor(ConversationKind::DirectMessage)
            ? $fields
            : [];
    }
}
