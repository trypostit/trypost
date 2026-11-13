<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\MetricValue;
use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\ExposureKind;
use App\Enums\Analytics\MetricAvailability;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricTimeBasis;
use App\Enums\Analytics\MetricUnit;
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
            $locked = filled($observation->providerMetadata)
                ? AnalyticsPublication::query()->lockForUpdate()->find($publication->id)
                : null;
            $snapshot = AnalyticsPublicationDailySnapshot::query()
                ->where('publication_id', $publication->id)
                ->where('date', $observation->date->toDateString())
                ->lockForUpdate()
                ->first();

            if (! $snapshot) {
                if (! $mayCreate) {
                    $snapshot = AnalyticsPublicationDailySnapshot::query()
                        ->where('publication_id', $publication->id)
                        ->where('date', $observation->date->toDateString())
                        ->lockForUpdate()
                        ->firstOrFail();
                } else {
                    $snapshot = new AnalyticsPublicationDailySnapshot([
                        'publication_id' => $publication->id,
                        'date' => $observation->date->toDateString(),
                    ]);
                }
            }

            $metrics = $this->mergeMetrics($snapshot->metrics ?? [], collect($observation->metrics)
                ->mapWithKeys(fn (MetricValue $metric): array => [$metric->key->value => $metric->toArray()])
                ->all());

            $snapshot->fill([
                'collected_at' => $observation->collectedAt ?? now(),
                'provider_observed_at' => $observation->providerObservedAt ?? $snapshot->provider_observed_at,
                'metrics' => $metrics ?: null,
                ...$this->scalarProjections($metrics),
            ]);
            $snapshot->save();

            if (filled($locked)) {
                $this->mergeProviderMetadata($locked, $observation->providerMetadata);
            }

            return $snapshot->refresh();
        });
    }

    /**
     * Folds a same-day snapshot of another publication into `$kept` (one post
     * stored twice, being merged) by the rule every same-day read follows: the
     * newer read's metrics merged over the older one's, the columns projected
     * from the result. A snapshot stored without metrics brought nothing to
     * merge, so the other one is kept whole, as a read that brings nothing
     * keeps what is stored.
     */
    public function combine(AnalyticsPublicationDailySnapshot $kept, AnalyticsPublicationDailySnapshot $other): void
    {
        [$older, $newer] = $other->collected_at->greaterThan($kept->collected_at) ? [$kept, $other] : [$other, $kept];

        if (blank($older->metrics) || blank($newer->metrics)) {
            $whole = blank($newer->metrics) ? $older : $newer;
            $kept->fill($whole->only(['collected_at', 'provider_observed_at', 'metrics', ...array_keys($this->scalarProjections([]))]))->save();

            return;
        }

        $metrics = $this->mergeMetrics($older->metrics, $newer->metrics);

        $kept->fill([
            'collected_at' => $newer->collected_at,
            'provider_observed_at' => $newer->provider_observed_at ?? $older->provider_observed_at,
            'metrics' => $metrics,
            ...$this->scalarProjections($metrics),
        ])->save();
    }

    /**
     * Writes the keys the network reported over the publication's stored
     * `provider_metadata`, on the row read again under the lock the transaction
     * took first (before the snapshot, the order discovery locks in): the model
     * was loaded before the network call, and a discovery may have written
     * meanwhile. A key the observation does not carry is kept, and `updated_at`
     * does not move.
     *
     * @param  array<string, mixed>  $providerMetadata
     */
    private function mergeProviderMetadata(AnalyticsPublication $current, array $providerMetadata): void
    {
        $current->withoutTimestamps(fn (): bool => $current->forceFill([
            'provider_metadata' => [...($current->provider_metadata ?? []), ...$providerMetadata],
        ])->saveQuietly());
    }

    /**
     * Same-day reads merge field by field; a lifetime count keeps its highest
     * value so a partial read (one call refused) cannot undercut a fuller one.
     *
     * @param  array<string, array<string, mixed>>  $existing
     * @param  array<string, array<string, mixed>>  $incoming  Stored metrics (MetricValue::toArray()) keyed by their key
     * @return array<string, array<string, mixed>>
     */
    private function mergeMetrics(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $metric) {
            $current = data_get($existing, $key);
            $currentIsMeasured = data_get($current, 'availability') === MetricAvailability::Available->value
                && is_numeric(data_get($current, 'value'));
            $incomingIsMeasured = data_get($metric, 'availability') === MetricAvailability::Available->value
                && is_numeric(data_get($metric, 'value'));

            $wouldLowerLifetimeCount = $incomingIsMeasured && $currentIsMeasured
                && data_get($metric, 'unit') === MetricUnit::Count->value
                && data_get($metric, 'time_basis') === MetricTimeBasis::Lifetime->value
                && data_get($current, 'time_basis', MetricTimeBasis::Lifetime->value) === MetricTimeBasis::Lifetime->value
                && data_get($metric, 'value') < data_get($current, 'value');

            if (($incomingIsMeasured || ! $currentIsMeasured) && ! $wouldLowerLifetimeCount) {
                $existing[$key] = $metric;
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
        $metric = data_get($metrics, $key->value);
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
