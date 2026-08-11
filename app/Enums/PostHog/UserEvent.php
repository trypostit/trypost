<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum UserEvent: string
{
    case SignedUp = 'user.signed_up';
}
