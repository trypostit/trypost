<?php

declare(strict_types=1);

namespace App\Enums\Inbox;

enum ResolveScope: string
{
    case Message = 'message';
    case Thread = 'thread';
    case Conversation = 'conversation';
    case All = 'all';
    case Older = 'older';

    public function needsMessage(): bool
    {
        return $this === self::Message || $this === self::Thread;
    }
}
