<?php

declare(strict_types=1);

namespace App\Enums\Repurpose;

enum Status: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Disabled = 'disabled';
}
