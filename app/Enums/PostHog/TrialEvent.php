<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum TrialEvent: string
{
    case Started = 'trial.started';
    case Converted = 'trial.converted';
}
