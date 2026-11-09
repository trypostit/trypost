<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

/**
 * Why a post or publication has no metrics to show.
 */
enum MetricsUnavailableReason: string
{
    case NotPublished = 'not_published';
    case DeletedOnNetwork = 'deleted_on_network';
    case PlatformNotSupported = 'platform_not_supported';
    case NotCollected = 'not_collected';
}
