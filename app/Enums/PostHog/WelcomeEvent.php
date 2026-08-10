<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum WelcomeEvent: string
{
    case Persona = 'welcome.persona';
    case Goals = 'welcome.goals';
    case Referral = 'welcome.referral';
}
