<?php

declare(strict_types=1);

namespace App\Enums\Inbox;

enum ConversationKind: string
{
    case Comment = 'comment';
    case Review = 'review';
    case Mention = 'mention';
    case DirectMessage = 'direct_message';
}
