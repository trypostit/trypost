<?php

declare(strict_types=1);

namespace App\Enums\Post;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum RecurrenceFrequency: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public function advance(CarbonInterface $from, int $steps): CarbonImmutable
    {
        $from = $from->toImmutable();

        return match ($this) {
            self::Day => $from->addDays($steps),
            self::Week => $from->addWeeks($steps),
            self::Month => $from->addMonthsNoOverflow($steps),
            self::Year => $from->addYearsNoOverflow($steps),
        };
    }
}
