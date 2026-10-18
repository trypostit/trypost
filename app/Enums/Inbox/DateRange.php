<?php

declare(strict_types=1);

namespace App\Enums\Inbox;

use Carbon\CarbonImmutable;

enum DateRange: string
{
    case Last7Days = '7d';
    case Last30Days = '30d';
    case Last6Months = '6m';
    case Last12Months = '12m';

    public const DEFAULT = self::Last12Months;

    public function since(): CarbonImmutable
    {
        return match ($this) {
            self::Last7Days => now()->subDays(7),
            self::Last30Days => now()->subDays(30),
            self::Last6Months => now()->subMonths(6),
            self::Last12Months => now()->subMonths(12),
        };
    }
}
