<?php

declare(strict_types=1);

namespace App\Enums\Inbox;

enum StatusFilter: string
{
    case All = 'all';
    case Unanswered = 'unanswered';
    case Replied = 'replied';
    case Resolved = 'resolved';

    public const DEFAULT = self::All;
}
