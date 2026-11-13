<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\MetricValue;
use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\ExposureKind;
use App\Enums\Analytics\MetricAvailability;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricPrecision;
use App\Enums\Analytics\MetricTimeBasis;
use App\Enums\Analytics\MetricUnit;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
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

            $metrics = $this->mergeMetrics($snapshot->metrics ?? [], $observation->metrics);

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
     * newer read applied over the older one. A stored entry this version cannot
     * read back is kept as it is where the older read has nothing under its key.
     * The columns come from the merged metrics when both reads stored them;
     * otherwise each column is the newer read's, else the older one's, and the
     * exposure is picked from them as exposure() picks it (reach, impressions,
     * then views).
     */
    public function combine(AnalyticsPublicationDailySnapshot $kept, AnalyticsPublicationDailySnapshot $other): void
    {
        [$older, $newer] = $other->collected_at->greaterThan($kept->collected_at) ? [$kept, $other] : [$other, $kept];
        [$readable, $unreadable] = collect($newer->metrics ?? [])
            ->map(fn (mixed $stored, string $key): ?MetricValue => self::storedMetric($key, $stored))
            ->partition(fn (?MetricValue $metric): bool => filled($metric));

        $metrics = $this->mergeMetrics(
            [...Arr::only($newer->metrics ?? [], $unreadable->keys()->all()), ...($older->metrics ?? [])],
            $readable->values()->all(),
        );

        $kept->fill([
            'collected_at' => $newer->collected_at,
            'provider_observed_at' => $newer->provider_observed_at ?? $older->provider_observed_at,
            'metrics' => $metrics ?: null,
            ...(filled($older->metrics) && filled($newer->metrics)
                ? $this->scalarProjections($metrics)
                : $this->combinedColumns($newer, $older)),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function combinedColumns(AnalyticsPublicationDailySnapshot $newer, AnalyticsPublicationDailySnapshot $older): array
    {
        $columns = collect(array_keys($this->scalarProjections([])))
            ->mapWithKeys(fn (string $column): array => [$column => data_get($newer, $column) ?? data_get($older, $column)]);
        $exposure = collect(['reach_count' => ExposureKind::Reach, 'impressions_count' => ExposureKind::Impressions, 'views_count' => ExposureKind::Views])
            ->filter(fn (ExposureKind $kind, string $column): bool => filled($columns->get($column)));

        return [
            ...$columns->all(),
            'exposure_count' => transform($exposure->keys()->first(), fn (string $column): mixed => $columns->get($column)),
            'exposure_kind' => $exposure->first()?->value,
        ];
    }

    /**
     * A metric as stored in a daily snapshot's `metrics` under `$key`, or null
     * when the stored entry is not one this version can read back.
     */
    private static function storedMetric(string $key, mixed $stored): ?MetricValue
    {
        $text = fn (string $field): string => is_string(data_get($stored, $field)) ? data_get($stored, $field) : '';
        $metricKey = MetricKey::tryFrom($key);
        $unit = MetricUnit::tryFrom($text('unit'));
        $timeBasis = MetricTimeBasis::tryFrom($text('time_basis'));
        $precision = MetricPrecision::tryFrom($text('precision'));
        $availability = MetricAvailability::tryFrom($text('availability'));
        $value = filled(data_get($stored, 'value')) ? data_get($stored, 'value') : null;
        $providerMetric = filled(data_get($stored, 'provider_metric')) ? data_get($stored, 'provider_metric') : null;
        $periodStart = self::storedDate(data_get($stored, 'period_start'));
        $periodEnd = self::storedDate(data_get($stored, 'period_end'));

        if (blank($metricKey) || blank($unit) || blank($timeBasis) || blank($precision) || blank($availability)
            || (filled($value) && ! is_int($value) && ! is_float($value))
            || (filled($providerMetric) && ! is_string($providerMetric))
            || $periodStart === false || $periodEnd === false) {
            return null;
        }

        return new MetricValue(
            key: $metricKey,
            value: $value,
            unit: $unit,
            timeBasis: $timeBasis,
            precision: $precision,
            availability: $availability,
            providerMetric: $providerMetric,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        );
    }

    /**
     * A stored period date: null when none, false when it cannot be read.
     */
    private static function storedDate(mixed $stored): CarbonImmutable|false|null
    {
        if (blank($stored)) {
            return null;
        }

        return is_string($stored) ? rescue(fn (): CarbonImmutable => CarbonImmutable::parse($stored), false, report: false) : false;
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
     * @param  list<MetricValue>  $incoming
     * @return array<string, array<string, mixed>>
     */
    private function mergeMetrics(array $existing, array $incoming): array
    {
        foreach ($incoming as $metric) {
            $key = $metric->key->value;
            $current = data_get($existing, $key);
            $currentIsMeasured = data_get($current, 'availability') === MetricAvailability::Available->value
                && is_numeric(data_get($current, 'value'));
            $incomingIsMeasured = $metric->availability === MetricAvailability::Available
                && $metric->value !== null;

            $wouldLowerLifetimeCount = $incomingIsMeasured && $currentIsMeasured
                && $metric->unit === MetricUnit::Count
                && $metric->timeBasis === MetricTimeBasis::Lifetime
                && data_get($current, 'time_basis', MetricTimeBasis::Lifetime->value) === MetricTimeBasis::Lifetime->value
                && $metric->value < data_get($current, 'value');

            if (($incomingIsMeasured || ! $currentIsMeasured) && ! $wouldLowerLifetimeCount) {
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
