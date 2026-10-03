<?php

declare(strict_types=1);

namespace App\Enums\PostTemplate;

enum Audience: string
{
    case Business = 'business';
    case Individual = 'individual';
}
