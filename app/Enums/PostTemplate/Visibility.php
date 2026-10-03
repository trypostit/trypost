<?php

declare(strict_types=1);

namespace App\Enums\PostTemplate;

enum Visibility: string
{
    case Personal = 'personal';
    case Team = 'team';
}
