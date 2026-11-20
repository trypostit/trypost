<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum LifecycleEvent: string
{
    case StageChanged = 'lifecycle_stage.changed';
}
