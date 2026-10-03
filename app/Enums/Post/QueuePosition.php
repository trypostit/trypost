<?php

declare(strict_types=1);

namespace App\Enums\Post;

enum QueuePosition: string
{
    case Next = 'next';
    case Top = 'top';
}
