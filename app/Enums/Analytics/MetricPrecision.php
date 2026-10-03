<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

enum MetricPrecision: string
{
    case Exact = 'exact';
    case Approximate = 'approximate';
    case Estimated = 'estimated';
    case Experimental = 'experimental';
}
