<?php

declare(strict_types=1);

namespace App\Enums\Inbox;

enum Sort: string
{
    case UnansweredFirst = 'unanswered_first';
    case Newest = 'newest';
    case Oldest = 'oldest';

    public const DEFAULT = self::UnansweredFirst;
}
