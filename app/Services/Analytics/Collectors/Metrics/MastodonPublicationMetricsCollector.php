<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Models\AnalyticsPublication;
use Carbon\CarbonImmutable;

class MastodonPublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        $response = $this->get(
            $account,
            "{$account->mastodonInstance()}/api/v1/statuses/{$publication->remote_id}",
            authenticated: $account->canReadMastodonStatuses(),
        );

        $status = (array) $response->json();

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Reactions, $status, 'favourites_count'),
            $this->count(MetricKey::Comments, $status, 'replies_count'),
            $this->count(MetricKey::Shares, $status, 'reblogs_count'),
        ])));
    }
}
