<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricUnit;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Support\Analytics\SyncCadence;
use App\Support\Analytics\YouTubeScopes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Lifetime counts come from the Data API `statistics` part, which is current;
 * the Analytics API lags one to three days and adds what only it reports
 * (shares, watch time, retention, playlist saves, subscribers).
 */
class YouTubePublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    private const int STATISTICS_BATCH_SIZE = 50;

    private const int STATISTICS_CACHE_HOURS = 6;

    private const array ANALYTICS_METRICS = [
        'views', 'engagedViews', 'estimatedMinutesWatched', 'averageViewDuration', 'averageViewPercentage',
        'likes', 'comments', 'shares', 'videosAddedToPlaylists', 'subscribersGained', 'subscribersLost',
    ];

    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        AnalyticsCollectionException::unlessGranted($account, YouTubeScopes::READ);
        $statistics = $this->statistics($account, $publication, $date);
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

    /**
     * Reads `statistics` for this video together with up to 49 other videos of the channel
     * still due on the same day, so the daily pass spends one quota unit per 50 videos.
     *
     * @return array<string, mixed>
     */
    private function statistics(SocialAccount $account, AnalyticsPublication $publication, CarbonImmutable $date): array
    {
        $key = $this->statisticsCacheKey($account, $date, $publication->remote_id);

        if (! Cache::has($key)) {
            $this->prefetchStatistics($account, $publication, $date);
        }

        $statistics = Cache::get($key);

        if (! is_array($statistics)) {
            throw AnalyticsCollectionException::gone('YouTube no longer returns this video.');
        }

        return $statistics;
    }

    private function prefetchStatistics(SocialAccount $account, AnalyticsPublication $publication, CarbonImmutable $date): void
    {
        $ids = AnalyticsPublication::query()
            ->available()
            ->where('social_account_id', $account->id)
            ->where('platform', Platform::YouTube)
            ->whereKeyNot($publication->id)
            ->where(fn (Builder $query) => $query
                ->where('provider_published_at', '>=', SyncCadence::metricsWindowStart(Platform::YouTube, $date))
                ->orWhereDoesntHave('dailySnapshots'))
            ->whereDoesntHave('dailySnapshots', fn (Builder $query) => $query->where('date', $date->toDateString()))
            ->latest('provider_published_at')
            ->limit(self::STATISTICS_BATCH_SIZE - 1)
            ->pluck('remote_id')
            ->prepend($publication->remote_id)
            ->unique()
            ->values();

        $videos = collect((array) $this->get($account,
            rtrim((string) config('trypost.platforms.youtube.data_api'), '/').'/videos',
            ['part' => 'statistics', 'id' => $ids->implode(',')],
        )->json('items', []))->filter(fn (mixed $video): bool => is_array($video))->keyBy('id');

        foreach ($ids as $id) {
            $video = $videos->get($id);

            Cache::put(
                $this->statisticsCacheKey($account, $date, $id),
                is_array($video) ? (array) data_get($video, 'statistics', []) : false,
                now()->addHours(self::STATISTICS_CACHE_HOURS),
            );
        }
    }

    private function statisticsCacheKey(SocialAccount $account, CarbonImmutable $date, string $videoId): string
    {
        return "analytics:youtube-statistics:{$account->id}:{$date->toDateString()}:{$videoId}";
    }

    /**
     * The Analytics row keyed by metric name; empty until YouTube has
     * processed the video, or when the channel refuses the report.
     *
     * @return array<string, mixed>
     */
    private function report(SocialAccount $account, AnalyticsPublication $publication, CarbonImmutable $date): array
    {
        if ($account->missingScope(YouTubeScopes::ANALYTICS) !== null) {
            return [];
        }

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
            if (! in_array($exception->category, ['permission', 'malformed'], true)) {
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
