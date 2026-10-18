<?php

declare(strict_types=1);

namespace App\Dto\Inbox;

/**
 * One person reacting to (or, with no emoji, unreacting from) a stored
 * direct message, from a Meta `message_reactions` webhook event.
 */
final readonly class InboxReaction
{
    public function __construct(
        public string $platformMessageId,
        public string $userId,
        public ?string $emoji,
    ) {}
}
