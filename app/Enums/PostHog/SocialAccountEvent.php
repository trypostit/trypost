<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum SocialAccountEvent: string
{
    case Connected = 'social_account.connected';
    case Disconnected = 'social_account.disconnected';
}
