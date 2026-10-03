<?php

declare(strict_types=1);

namespace App\Enums\PostTemplate;

enum Format: string
{
    case Single = 'single';
    case Thread = 'thread';
}
