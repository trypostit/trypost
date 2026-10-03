<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricUnit;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

/**
 * Lifetime counts come from the Data API `statistics` part, which is current;
 * the Analytics API lags one to three days and adds what only it reports
 * (shares, watch time, retention, playlist saves, subscribers).
 */
class YouTubePublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    private const array ANALYTICS_METRICS = [
        'views', 'engagedViews', 'estimatedMinutesWatched', 'averageViewDuration', 'averageViewPercentage',
        'likes', 'comments', 'shares', 'videosAddedToPlaylists', 'subscribersGained', 'subscribersLost',
    ];

    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        $statistics = $this->statistics($account, $publication);
        $report = $this->report($account, $publication, $date);

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Views, $statistics, 'viewCount') ?? $this->count(MetricKey::Views, $report, 'views'),
            $this->count(MetricKey::Reactions, $statistics, 'likeCount') ?? $this->count(MetricKey::Reactions, $report, 'likes'),
            $this->count(MetricKey::Comments, $statistics, 'commentCount') ?? $this->count(MetricKey::Comments, $report, 'comments'),
            $this->count(MetricKey::EngagedViews, $report, 'engagedViews'),
            $this->decimal(MetricKey::WatchTimeMilliseconds, $report, 'estimatedMinutesWatched', MetricUnit::Milliseconds, 60000),
            $this->decimal(MetricKey::AverageWatchTimeMilliseconds, $report, 'averageViewDuration', MetricUnit::Milliseconds, 1000),
            $this->decimal(MetricKey::AveragePercentageViewed, $report, 'averageViewPercentage', MetricUnit::Percent),
            $this->count(MetricKey::Shares, $report, 'shares'),
            $this->count(MetricKey::Saves, $report, 'videosAddedToPlaylists'),
            $this->count(MetricKey::SubscribersGained, $report, 'subscribersGained'),
            $this->count(MetricKey::SubscribersLost, $report, 'subscribersLost'),
        ])));
    }

    /** @return array<string, mixed> */
    private function statistics(SocialAccount $account, AnalyticsPublication $publication): array
    {
        $video = $this->get($account,
            rtrim((string) config('trypost.platforms.youtube.data_api'), '/').'/videos',
            ['part' => 'statistics', 'id' => $publication->remote_id],
        )->json('items.0');

        if (! is_array($video) || data_get($video, 'id') !== $publication->remote_id) {
            throw AnalyticsCollectionException::malformed('YouTube video statistics response does not match the publication.');
        }

        return (array) data_get($video, 'statistics', []);
    }

    /**
     * The Analytics row keyed by metric name; empty until YouTube has
     * processed the video, or when the channel refuses the report.
     *
     * @return array<string, mixed>
     */
    private function report(SocialAccount $account, AnalyticsPublication $publication, CarbonImmutable $date): array
    {
        try {
            $response = $this->get($account,
                rtrim((string) config('trypost.platforms.youtube.analytics_api'), '/').'/reports',
                [
                    'ids' => 'channel==MINE',
                    'startDate' => $publication->provider_published_at->toDateString(),
                    'endDate' => $date->toDateString(),
                    'metrics' => implode(',', self::ANALYTICS_METRICS),
                    'filters' => "video=={$publication->remote_id}",
                ],
            );
        } catch (AnalyticsCollectionException $exception) {
            if ($exception->category !== 'permission') {
                throw $exception;
            }

            return [];
        }

        $row = $response->json('rows.0');

        if (! is_array($row)) {
            return [];
        }

        $values = [];

        foreach ((array) $response->json('columnHeaders', []) as $index => $header) {
            $name = data_get($header, 'name');

            if (is_string($name) && array_key_exists($index, $row)) {
                $values[$name] = $row[$index];
            }
        }

        return $values;
    }
}
