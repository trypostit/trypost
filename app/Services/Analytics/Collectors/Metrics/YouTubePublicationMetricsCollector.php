<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Contracts\Analytics\PublicationMetricsCollector;
use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricUnit;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class YouTubePublicationMetricsCollector extends AbstractPublicationMetricsCollector implements PublicationMetricsCollector
{
    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        $response = $this->get($account,
            rtrim((string) config('trypost.platforms.youtube.analytics_api'), '/').'/reports',
            [
                'ids' => 'channel==MINE',
                'startDate' => $publication->provider_published_at->toDateString(),
                'endDate' => $date->toDateString(),
                'metrics' => 'views,engagedViews,estimatedMinutesWatched,averageViewDuration,averageViewPercentage,likes,comments,shares,subscribersGained,subscribersLost',
                'filters' => "video=={$publication->provider_post_id}",
            ],
        );
        $headers = $response->json('columnHeaders');
        $row = $response->json('rows.0');

        if (! is_array($headers)) {
            throw AnalyticsCollectionException::malformed('YouTube Analytics response lacks column headers.');
        }

        if ($row === null) {
            return $this->currentVideoStatistics($account, $publication, $date);
        }

        $values = [];

        if (is_array($row)) {
            foreach ($headers as $index => $header) {
                if (is_string(data_get($header, 'name')) && array_key_exists($index, $row)) {
                    $values[$header['name']] = $row[$index];
                }
            }
        }

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Views, $values, 'views'),
            $this->count(MetricKey::EngagedViews, $values, 'engagedViews'),
            $this->decimal(MetricKey::WatchTimeMilliseconds, $values, 'estimatedMinutesWatched', MetricUnit::Milliseconds, 60000),
            $this->decimal(MetricKey::AverageWatchTimeMilliseconds, $values, 'averageViewDuration', MetricUnit::Milliseconds, 1000),
            $this->decimal(MetricKey::AveragePercentageViewed, $values, 'averageViewPercentage', MetricUnit::Percent),
            $this->count(MetricKey::Reactions, $values, 'likes'),
            $this->count(MetricKey::Comments, $values, 'comments'),
            $this->count(MetricKey::Shares, $values, 'shares'),
            $this->count(MetricKey::SubscribersGained, $values, 'subscribersGained'),
            $this->count(MetricKey::SubscribersLost, $values, 'subscribersLost'),
        ])));
    }

    private function currentVideoStatistics(
        SocialAccount $account,
        AnalyticsPublication $publication,
        CarbonImmutable $date,
    ): PublicationMetricObservation {
        $response = $this->get($account,
            rtrim((string) config('trypost.platforms.youtube.data_api'), '/').'/videos',
            [
                'part' => 'statistics',
                'id' => $publication->provider_post_id,
            ],
        );
        $video = $response->json('items.0');

        if (! is_array($video) || data_get($video, 'id') !== $publication->provider_post_id) {
            throw AnalyticsCollectionException::malformed('YouTube video statistics response does not match the publication.');
        }

        $statistics = (array) data_get($video, 'statistics', []);

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Views, $statistics, 'viewCount'),
            $this->count(MetricKey::Reactions, $statistics, 'likeCount'),
            $this->count(MetricKey::Comments, $statistics, 'commentCount'),
        ])));
    }
}
