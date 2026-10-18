<?php

declare(strict_types=1);

namespace App\Services\Inbox\Contracts;

use App\Dto\Inbox\MessageLimit;
use App\Dto\Inbox\SentReply;
use App\Models\InboxConversation;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

/**
 * An inbox driver that also reads and sends 1:1 direct messages.
 */
interface DirectMessageDriver
{
    public function sendDirectMessage(SocialAccount $account, InboxConversation $conversation, string $text): SentReply;

    /**
     * The text the network receives for what the user wrote (Mastodon
     * prepends the participant's mention).
     */
    public function directMessageText(InboxConversation $conversation, string $text): string;

    public function directMessageLimit(SocialAccount $account): MessageLimit;

    /**
     * Whether the network only accepts answers for a while after the
     * person's last message; with a window, no message from them means no
     * answer is possible.
     */
    public function hasReplyWindow(): bool;

    /**
     * Until when the network accepts an answer, from the person's last
     * message; null when it has no window.
     */
    public function replyWindowEndsAt(?CarbonImmutable $lastInboundAt): ?CarbonImmutable;
}
