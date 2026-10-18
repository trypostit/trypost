<?php

declare(strict_types=1);

namespace App\Enums\Inbox;

enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
