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
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

class PinterestPublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        AnalyticsCollectionException::unlessGranted($account, 'pins:read', 'boards:read');
        $isVideo = in_array($publication->content_type, [PublicationContentType::Video, PublicationContentType::Short], true);
        $fields = ['IMPRESSION', 'SAVE', 'PIN_CLICK', 'OUTBOUND_CLICK', 'SAVE_RATE', 'TOTAL_COMMENTS', 'TOTAL_REACTIONS'];

        if ($isVideo) {
            $fields = array_merge($fields, ['VIDEO_MRC_VIEW', 'VIDEO_AVG_WATCH_TIME', 'VIDEO_10S_VIEW', 'QUARTILE_95_PERCENT_VIEW', 'VIDEO_V50_WATCH_TIME']);
        }

        $api = rtrim((string) config('trypost.platforms.pinterest.api'), '/');

        $response = $this->readUnlessDeleted(
            fn (): Response => $this->get($account, "{$api}/pins/{$publication->remote_id}/analytics", [
                'start_date' => $date->subDays(89)->toDateString(),
                'end_date' => $date->toDateString(),
                'metric_types' => implode(',', $fields),
            ]),
            fn (AnalyticsCollectionException $exception): bool => $exception->gone && $this->confirmsDeletion($account, $publication, $api),
            'Pinterest',
        );

        $values = $response->json('all.summary_metrics');

        if (! is_array($values)) {
            throw AnalyticsCollectionException::malformed('Pinterest Pin analytics response lacks summary metrics.');
        }

        $lifetime = [...$values, ...(array) $response->json('all.lifetime_metrics', [])];
        $basis = MetricTimeBasis::Rolling90Days;
        $metrics = $this->present([
            $this->count(MetricKey::Impressions, $values, 'IMPRESSION', $basis),
            $this->count(MetricKey::Saves, $values, 'SAVE', $basis),
            $this->count(MetricKey::PinClicks, $values, 'PIN_CLICK', $basis),
            $this->count(MetricKey::OutboundClicks, $values, 'OUTBOUND_CLICK', $basis),
            $this->decimal(MetricKey::SaveRate, $values, 'SAVE_RATE', MetricUnit::Percent, 100, $basis),
            $this->count(MetricKey::VideoViews, $values, 'VIDEO_MRC_VIEW', $basis),
            $this->decimal(MetricKey::AverageVideoPlayTimeMilliseconds, $values, 'VIDEO_AVG_WATCH_TIME', MetricUnit::Milliseconds, timeBasis: $basis),
            $this->count(MetricKey::VideoViews10Seconds, $values, 'VIDEO_10S_VIEW', $basis),
            $this->count(MetricKey::VideoViews95Percent, $values, 'QUARTILE_95_PERCENT_VIEW', $basis),
            $this->decimal(MetricKey::TotalPlayTimeMilliseconds, $values, 'VIDEO_V50_WATCH_TIME', MetricUnit::Milliseconds, timeBasis: $basis),
            $this->count(MetricKey::Comments, $lifetime, 'TOTAL_COMMENTS'),
            $this->count(MetricKey::Reactions, $lifetime, 'TOTAL_REACTIONS'),
        ]);

        return $this->observation($date, $this->withEngagements($metrics));
    }

    /**
     * Pinterest documents only the 404 status for a Pin it cannot find, so the Pin
     * itself must answer 404 too, while the account's Pins, the list discovery reads,
     * still read with the same token.
     */
    private function confirmsDeletion(SocialAccount $account, AnalyticsPublication $publication, string $api): bool
    {
        return $this->readsMissing(
            fn (): Response => $this->get($account, "{$api}/pins/{$publication->remote_id}"),
            fn (AnalyticsCollectionException $exception): bool => $exception->gone,
        ) && $this->stillReads(
            fn (): Response => $this->get($account, "{$api}/pins", ['page_size' => 1]),
        );
    }
}
