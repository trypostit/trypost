<?php

declare(strict_types=1);

namespace App\Enums\Post;

enum ScheduleMode: string
{
    case Queue = 'queue';
    case Custom = 'custom';
}
