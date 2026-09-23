<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Contracts\Analytics\PublicationMetricsCollector;
use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use Carbon\CarbonImmutable;

class ThreadsPublicationMetricsCollector extends AbstractPublicationMetricsCollector implements PublicationMetricsCollector
{
    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        $response = $this->get($account,
            rtrim((string) config('trypost.platforms.threads.graph_api'), '/')."/{$publication->provider_post_id}/insights",
            ['metric' => 'views,likes,replies,reposts,quotes'],
        );
        $items = $response->json('data');

        if (! is_array($items)) {
            throw AnalyticsCollectionException::malformed('Threads insights response lacks data.');
        }

        $values = $this->insights($items);

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Views, $values, 'views'),
            $this->count(MetricKey::Reactions, $values, 'likes'),
            $this->count(MetricKey::Comments, $values, 'replies'),
            $this->count(MetricKey::Shares, $values, 'reposts'),
            $this->count(MetricKey::Quotes, $values, 'quotes'),
        ])));
    }
}
