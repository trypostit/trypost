<?php

declare(strict_types=1);

namespace App\Enums\Inbox;

/**
 * Why a channel's kind stopped syncing, as the inbox shows it: the network
 * turned the channel's messages off, the channel lost a permission, or the
 * sync keeps failing for another reason.
 */
enum SyncError: string
{
    case MessagesUnavailable = 'messages_unavailable';
    case Permission = 'permission';
    case Failing = 'failing';

    public const int FAILING_AFTER = 3;
}
