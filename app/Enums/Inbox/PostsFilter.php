<?php

declare(strict_types=1);

namespace App\Enums\Inbox;

enum PostsFilter: string
{
    case Commented = 'commented';
    case All = 'all';

    public const DEFAULT = self::Commented;
}
