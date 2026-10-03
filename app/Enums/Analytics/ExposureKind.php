<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

enum ExposureKind: string
{
    case Reach = 'reach';
    case Impressions = 'impressions';
    case Views = 'views';
}
