<?php

declare(strict_types=1);

namespace App\Contracts\Analytics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Models\AnalyticsPublication;
use Carbon\CarbonImmutable;

interface PublicationMetricsCollector
{
    public function collect(
        AnalyticsPublication $publication,
        CarbonImmutable $date,
    ): PublicationMetricObservation;
}
