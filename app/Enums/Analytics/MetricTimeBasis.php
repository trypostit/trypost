<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

enum MetricTimeBasis: string
{
    case Lifetime = 'lifetime';
    case Range = 'range';
    case Rolling90Days = 'rolling_90_days';
    case Snapshot = 'snapshot';
}
