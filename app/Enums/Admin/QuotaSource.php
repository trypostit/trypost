<?php

declare(strict_types=1);

namespace App\Enums\Admin;

enum QuotaSource: string
{
    case YouTube = 'youtube';
    case X = 'x';
}
