<?php

declare(strict_types=1);

namespace App\Support\Analytics;

class EngagementRate
{
    /** Engagements per exposure (reach, else impressions, else views), in percent. */
    public static function of(mixed $engagements, mixed $exposure): ?float
    {
        if ($engagements === null || (int) $exposure <= 0) {
            return null;
        }

        return round((int) $engagements / (int) $exposure * 100, 2);
    }
}
