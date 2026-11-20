<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum RepurposeEvent: string
{
    case Activated = 'repurpose.activated';
}
