<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum PlanEvent: string
{
    case Upgraded = 'plan.upgraded';
    case Downgraded = 'plan.downgraded';
}
