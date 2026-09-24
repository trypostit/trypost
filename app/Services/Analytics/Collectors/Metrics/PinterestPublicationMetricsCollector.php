<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricTimeBasis;
use App\Enums\Analytics\MetricUnit;
use App\Enums\Analytics\PublicationContentType;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use Carbon\CarbonImmutable;

class PinterestPublicationMetricsCollector extends AbstractPublicationMetricsCollector implements PublicationMetricsCollector
{
    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        $isVideo = in_array($publication->content_type, [PublicationContentType::Video, PublicationContentType::Short], true);
        $fields = ['IMPRESSION', 'SAVE', 'PIN_CLICK', 'OUTBOUND_CLICK', 'ENGAGEMENT', 'ENGAGEMENT_RATE', 'SAVE_RATE', 'PIN_CLICK_RATE', 'OUTBOUND_CLICK_RATE'];

        if ($isVideo) {
            $fields = array_merge($fields, ['VIDEO_MRC_VIEW', 'VIDEO_AVG_WATCH_TIME', 'VIDEO_10S_VIEW', 'QUARTILE_95_PERCENT_VIEW', 'VIDEO_V50_WATCH_TIME']);
        }

        $response = $this->get($account,
            rtrim((string) config('trypost.platforms.pinterest.api'), '/')."/pins/{$publication->remote_id}/analytics",
            [
                'start_date' => $date->subDays(89)->toDateString(),
                'end_date' => $date->toDateString(),
                'metric_types' => implode(',', $fields),
            ],
        );
        $values = $response->json('all.summary_metrics');

        if (! is_array($values)) {
            throw AnalyticsCollectionException::malformed('Pinterest Pin analytics response lacks summary metrics.');
        }

        $basis = MetricTimeBasis::Rolling90Days;
        $metrics = $this->present([
            $this->count(MetricKey::Impressions, $values, 'IMPRESSION', $basis),
            $this->count(MetricKey::Saves, $values, 'SAVE', $basis),
            $this->count(MetricKey::PinClicks, $values, 'PIN_CLICK', $basis),
            $this->count(MetricKey::OutboundClicks, $values, 'OUTBOUND_CLICK', $basis),
            $this->count(MetricKey::Engagements, $values, 'ENGAGEMENT', $basis),
            $this->decimal(MetricKey::EngagementRate, $values, 'ENGAGEMENT_RATE', MetricUnit::Percent, 100, $basis),
            $this->decimal(MetricKey::SaveRate, $values, 'SAVE_RATE', MetricUnit::Percent, 100, $basis),
            $this->decimal(MetricKey::PinClickRate, $values, 'PIN_CLICK_RATE', MetricUnit::Percent, 100, $basis),
            $this->decimal(MetricKey::OutboundClickRate, $values, 'OUTBOUND_CLICK_RATE', MetricUnit::Percent, 100, $basis),
            $this->count(MetricKey::VideoViews, $values, 'VIDEO_MRC_VIEW', $basis),
            $this->decimal(MetricKey::AverageVideoPlayTimeMilliseconds, $values, 'VIDEO_AVG_WATCH_TIME', MetricUnit::Milliseconds, timeBasis: $basis),
            $this->count(MetricKey::VideoViews10Seconds, $values, 'VIDEO_10S_VIEW', $basis),
            $this->count(MetricKey::VideoViews95Percent, $values, 'QUARTILE_95_PERCENT_VIEW', $basis),
            $this->decimal(MetricKey::TotalPlayTimeMilliseconds, $values, 'VIDEO_V50_WATCH_TIME', MetricUnit::Milliseconds, timeBasis: $basis),
        ]);
        $details = $this->get($account,
            rtrim((string) config('trypost.platforms.pinterest.api'), '/')."/pins/{$publication->remote_id}",
            ['pin_metrics' => 'true'],
        )->json();
        $lifetime = (array) (data_get($details, 'pin_metrics.all.lifetime_metrics')
            ?? data_get($details, 'pin_metrics.lifetime_metrics', []));
        $metrics = array_merge($metrics, $this->present([
            $this->count(MetricKey::Comments, $lifetime, 'TOTAL_COMMENTS'),
            $this->count(MetricKey::Reactions, $lifetime, 'TOTAL_REACTIONS'),
        ]));

        return $this->observation($date, $metrics);
    }
}
