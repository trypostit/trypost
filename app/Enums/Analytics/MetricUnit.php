<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

enum MetricUnit: string
{
    case Count = 'count';
    case Milliseconds = 'milliseconds';
    case Percent = 'percent';
}
