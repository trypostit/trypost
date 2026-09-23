<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

enum MetricAvailability: string
{
    case Available = 'available';
    case Unsupported = 'unsupported';
    case Unavailable = 'unavailable';
    case Delayed = 'delayed';
    case PrivacyLimited = 'privacy_limited';
}
