<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum ActivationEvent: string
{
    case Reached = 'activation.reached';
}
