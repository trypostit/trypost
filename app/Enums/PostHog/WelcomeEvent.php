<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum WelcomeEvent: string
{
    case PersonaSaved = 'welcome.persona_saved';
    case GoalsSaved = 'welcome.goals_saved';
    case ReferralSaved = 'welcome.referral_saved';
    case CheckoutStarted = 'welcome.checkout_started';
}
