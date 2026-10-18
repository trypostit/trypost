<?php

declare(strict_types=1);

namespace App\Services\Inbox\Contracts;

use App\Dto\Inbox\PollResult;
use App\Dto\Inbox\SentReply;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

interface InboxDriver
{
    /** @return list<ConversationKind> */
    public function kinds(): array;

    public function poll(SocialAccount $account, ConversationKind $kind, ?string $cursor, CarbonImmutable $since): PollResult;

    public function reply(SocialAccount $account, InboxMessage $parent, string $text): SentReply;

    public function replyLength(SocialAccount $account): int;

    public function replyLengthUnit(): ReplyLengthUnit;

    public function prefillsMention(): bool;
}
