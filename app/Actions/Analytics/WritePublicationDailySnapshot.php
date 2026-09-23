<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\MetricValue;
use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\ExposureKind;
use App\Enums\Analytics\MetricAvailability;
use App\Enums\Analytics\MetricKey;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class WritePublicationDailySnapshot
{
    public function handle(
        AnalyticsPublication $publication,
        PublicationMetricObservation $observation,
    ): AnalyticsPublicationDailySnapshot {
        try {
            return $this->write($publication, $observation, true);
        } catch (UniqueConstraintViolationException) {
            return $this->write($publication, $observation, false);
        }
    }

    private function write(
        AnalyticsPublication $publication,
        PublicationMetricObservation $observation,
        bool $mayCreate,
    ): AnalyticsPublicationDailySnapshot {
        return DB::transaction(function () use ($publication, $observation, $mayCreate): AnalyticsPublicationDailySnapshot {
            $snapshot = AnalyticsPublicationDailySnapshot::query()
                ->where('analytics_publication_id', $publication->id)
                ->whereDate('snapshot_date', $observation->date->toDateString())
                ->lockForUpdate()
                ->first();

            if (! $snapshot) {
                if (! $mayCreate) {
                    $snapshot = AnalyticsPublicationDailySnapshot::query()
                        ->where('analytics_publication_id', $publication->id)
                        ->whereDate('snapshot_date', $observation->date->toDateString())
                        ->lockForUpdate()
                        ->firstOrFail();
                } else {
                    $snapshot = new AnalyticsPublicationDailySnapshot([
                        'analytics_publication_id' => $publication->id,
                        'snapshot_date' => $observation->date->toDateString(),
                    ]);
                }
            }

            $metrics = $this->mergeMetrics($snapshot->metrics ?? [], $observation->metrics);

            $snapshot->fill([
                'collected_at' => $observation->collectedAt ?? now(),
                'provider_observed_at' => $observation->providerObservedAt ?? $snapshot->provider_observed_at,
                'metrics' => $metrics ?: null,
                ...$this->scalarProjections($metrics),
            ]);
            $snapshot->save();

            return $snapshot->refresh();
        });
    }

    /**
     * @param  array<string, array<string, mixed>>  $existing
     * @param  list<MetricValue>  $incoming
     * @return array<string, array<string, mixed>>
     */
    private function mergeMetrics(array $existing, array $incoming): array
    {
        foreach ($incoming as $metric) {
            $key = $metric->key->value;
            $current = $existing[$key] ?? null;
            $currentIsMeasured = data_get($current, 'availability') === MetricAvailability::Available->value
                && is_numeric(data_get($current, 'value'));
            $incomingIsMeasured = $metric->availability === MetricAvailability::Available
                && $metric->value !== null;

            if ($incomingIsMeasured || ! $currentIsMeasured) {
                $existing[$key] = $metric->toArray();
            }
        }

        return $existing;
    }

    /**
     * @param  array<string, array<string, mixed>>  $metrics
     * @return array<string, int|string|null>
     */
    private function scalarProjections(array $metrics): array
    {
        [$exposureCount, $exposureKind] = $this->exposure($metrics);

        return [
            'reactions_count' => $this->measuredInteger($metrics, MetricKey::Reactions),
            'comments_count' => $this->measuredInteger($metrics, MetricKey::Comments),
            'shares_count' => $this->measuredInteger($metrics, MetricKey::Shares),
            'saves_count' => $this->measuredInteger($metrics, MetricKey::Saves),
            'views_count' => $this->measuredInteger($metrics, MetricKey::Views),
            'impressions_count' => $this->measuredInteger($metrics, MetricKey::Impressions),
            'reach_count' => $this->measuredInteger($metrics, MetricKey::Reach),
            'engagement_count' => $this->measuredInteger($metrics, MetricKey::Engagements),
            'exposure_count' => $exposureCount,
            'exposure_kind' => $exposureKind,
            'watch_time_milliseconds' => $this->measuredInteger($metrics, MetricKey::WatchTimeMilliseconds),
            'average_watch_time_milliseconds' => $this->measuredInteger($metrics, MetricKey::AverageWatchTimeMilliseconds),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $metrics
     * @return array{int|null, string|null}
     */
    private function exposure(array $metrics): array
    {
        foreach ([
            MetricKey::Reach->value => ExposureKind::Reach,
            MetricKey::Impressions->value => ExposureKind::Impressions,
            MetricKey::Views->value => ExposureKind::Views,
        ] as $key => $kind) {
            $value = $this->measuredInteger($metrics, MetricKey::from($key));

            if ($value !== null) {
                return [$value, $kind->value];
            }
        }

        return [null, null];
    }

    /** @param array<string, array<string, mixed>> $metrics */
    private function measuredInteger(array $metrics, MetricKey $key): ?int
    {
        $metric = $metrics[$key->value] ?? null;
        $value = data_get($metric, 'value');

        if (
            data_get($metric, 'availability') !== MetricAvailability::Available->value
            || ! is_numeric($value)
        ) {
            return null;
        }

        return (int) $value;
    }
}
