<?php

declare(strict_types=1);

namespace App\Enums\Tour;

use App\Enums\PostHog\TourEvent;

/**
 * How a tour ended: the user reached its last step, or closed it before.
 */
enum Outcome: string
{
    case Completed = 'completed';
    case Skipped = 'skipped';

    public function event(): TourEvent
    {
        return match ($this) {
            self::Completed => TourEvent::Completed,
            self::Skipped => TourEvent::Skipped,
        };
    }
}
