<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Models\AnalyticsPublication;
use Carbon\CarbonImmutable;

class MastodonPublicationMetricsCollector extends AbstractPublicationMetricsCollector implements PublicationMetricsCollector
{
    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        $instance = rtrim((string) data_get($account->meta, 'instance', config('trypost.platforms.mastodon.default_instance')), '/');
        $response = $this->get($account, "{$instance}/api/v1/statuses/{$publication->remote_id}");

        $status = (array) $response->json();

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Reactions, $status, 'favourites_count'),
            $this->count(MetricKey::Comments, $status, 'replies_count'),
            $this->count(MetricKey::Shares, $status, 'reblogs_count'),
        ])));
    }
}
