<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Support\Analytics\MetaAnalyticsResponse;
use Illuminate\Http\Client\Response;

abstract class AbstractMetaPublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    protected function successfulResponse(Response $response): Response
    {
        return MetaAnalyticsResponse::successful($response, 'publication metrics collection');
    }
}
