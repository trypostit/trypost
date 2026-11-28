<?php

declare(strict_types=1);

namespace App\Enums\Quota;

enum QuotaSource: string
{
    case YouTube = 'youtube';
    case X = 'x';
}
