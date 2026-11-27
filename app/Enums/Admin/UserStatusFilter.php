<?php

declare(strict_types=1);

namespace App\Enums\Admin;

enum UserStatusFilter: string
{
    case All = 'all';
    case Blocked = 'blocked';
}
