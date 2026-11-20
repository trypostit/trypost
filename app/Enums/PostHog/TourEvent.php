<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum TourEvent: string
{
    case Completed = 'tour.completed';
    case Skipped = 'tour.skipped';
}
