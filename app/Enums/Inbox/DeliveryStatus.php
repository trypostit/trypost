<?php

declare(strict_types=1);

namespace App\Enums\Inbox;

enum DeliveryStatus: string
{
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
}
