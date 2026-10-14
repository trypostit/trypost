<?php

declare(strict_types=1);

namespace App\Enums\Changelog;

enum Tag: string
{
    case New = 'new';
    case Beta = 'beta';
}
