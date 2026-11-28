<?php

declare(strict_types=1);

namespace App\Services\Admin\Quotas;

use Carbon\CarbonImmutable;
use Google\Client as GoogleClient;
use Google\Service\Monitoring;
use Google\Service\Monitoring\TimeSeries;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * YouTube's project quota as Google Cloud Monitoring reports it: the units
 * spent today per quota metric and each metric's limits. YouTube's day starts
 * at midnight Pacific time, and so does the reading.
 *
 * @see https://developers.google.com/youtube/v3/getting-started#quota
 * @see https://docs.cloud.google.com/monitoring/alerts/using-quota-metrics
 */
class YouTubeQuotaReader
{
    private const string SERVICE = 'youtube.googleapis.com';

    /** The shortest alignment period Cloud Monitoring accepts, in seconds. */
    private const int MIN_ALIGNMENT_SECONDS = 60;

    public function isConfigured(): bool
    {
        if (! config('trypost.admin.quotas.youtube.enabled')) {
            return false;
        }

        return filled(config('trypost.admin.quotas.youtube.project_id'))
            && filled(config('trypost.admin.quotas.youtube.credentials'));
    }

    /**
     * @return array{quotas: list<array{metric: string, usage: int, limits: list<array{name: string, value: int}>}>, since: string}
     */
    public function read(): array
    {
        $now = CarbonImmutable::now('UTC');
        $since = $now->setTimezone((string) config('trypost.admin.quotas.youtube.timezone'))->startOfDay()->utc();
        $alignmentPeriod = max(self::MIN_ALIGNMENT_SECONDS, (int) $now->diffInSeconds($since, true));
        $interval = [
            'interval.startTime' => $since->toIso8601ZuluString(),
            'interval.endTime' => $now->toIso8601ZuluString(),
        ];

        $usage = collect($this->fetchTimeSeries([
            'filter' => $this->filter('quota/rate/net_usage'),
            ...$interval,
            'aggregation.alignmentPeriod' => "{$alignmentPeriod}s",
            'aggregation.perSeriesAligner' => 'ALIGN_SUM',
            'aggregation.crossSeriesReducer' => 'REDUCE_SUM',
            'aggregation.groupByFields' => ['metric.labels.quota_metric'],
        ]));

        $limits = collect($this->fetchTimeSeries([
            'filter' => $this->filter('quota/limit'),
            ...$interval,
            'aggregation.alignmentPeriod' => "{$alignmentPeriod}s",
            'aggregation.perSeriesAligner' => 'ALIGN_MAX',
            'aggregation.crossSeriesReducer' => 'REDUCE_MAX',
            'aggregation.groupByFields' => ['metric.labels.quota_metric', 'metric.labels.limit_name'],
        ]))->groupBy(fn (array $series): string => (string) data_get($series, 'labels.quota_metric'));

        return [
            'quotas' => $usage
                ->map(fn (array $series): array => [
                    'metric' => (string) data_get($series, 'labels.quota_metric'),
                    'usage' => (int) data_get($series, 'value'),
                    'limits' => $limits->get((string) data_get($series, 'labels.quota_metric'), collect())
                        ->map(fn (array $limit): array => [
                            'name' => (string) data_get($limit, 'labels.limit_name'),
                            'value' => (int) data_get($limit, 'value'),
                        ])
                        ->values()
                        ->all(),
                ])
                ->sortBy('metric')
                ->values()
                ->all(),
            'since' => $since->toIso8601String(),
        ];
    }

    /**
     * One request to `projects.timeSeries.list`, each series reduced to its
     * labels (metric and resource) and the value of its latest point.
     *
     * @param  array<string, mixed>  $query
     * @return list<array{labels: array<string, string>, value: int}>
     */
    public function fetchTimeSeries(array $query): array
    {
        $projectId = (string) config('trypost.admin.quotas.youtube.project_id');

        $response = (new Monitoring($this->client()))
            ->projects_timeSeries
            ->listProjectsTimeSeries("projects/{$projectId}", $query);

        return collect($response->getTimeSeries())
            ->map(fn (TimeSeries $series): array => [
                'labels' => [
                    ...($series->getResource()?->getLabels() ?? []),
                    ...($series->getMetric()?->getLabels() ?? []),
                ],
                'value' => $this->latestValue($series),
            ])
            ->values()
            ->all();
    }

    private function latestValue(TimeSeries $series): int
    {
        $point = Collection::make($series->getPoints())->first();
        $value = $point?->getValue();

        if (blank($value)) {
            return 0;
        }

        if (filled($value->getInt64Value())) {
            return (int) $value->getInt64Value();
        }

        return (int) round((float) $value->getDoubleValue());
    }

    private function filter(string $metric): string
    {
        $service = self::SERVICE;

        return "metric.type=\"serviceruntime.googleapis.com/{$metric}\" AND resource.type=\"consumer_quota\" AND resource.labels.service=\"{$service}\"";
    }

    private function client(): GoogleClient
    {
        $client = app(GoogleClient::class);
        $client->setAuthConfig(Collection::fromJson(Str::fromBase64(config()->string('trypost.admin.quotas.youtube.credentials')))->all());
        $client->setScopes([Monitoring::MONITORING_READ]);

        return $client;
    }
}
