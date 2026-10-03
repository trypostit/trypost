<?php

declare(strict_types=1);

namespace App\Support\Analytics;

class DiscoverySchedule
{
    public static function cron(int $hours): string
    {
        $hours = max(1, min(24, $hours));

        return "0 */{$hours} * * *";
    }
}
