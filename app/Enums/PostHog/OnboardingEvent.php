<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum OnboardingEvent: string
{
    case Viewed = 'onboarding.viewed';
    case StepCompleted = 'onboarding.step_completed';
    case StepSkipped = 'onboarding.step_skipped';
    case Completed = 'onboarding.completed';
}
