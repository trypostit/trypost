<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\MetricValue;
use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricAvailability;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricPrecision;
use App\Enums\Analytics\MetricTimeBasis;
use App\Enums\Analytics\MetricUnit;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\AbstractApiPublicationCollector;
use Carbon\CarbonImmutable;

abstract class AbstractPublicationMetricsCollector extends AbstractApiPublicationCollector
{
    protected function account(AnalyticsPublication $publication): SocialAccount
    {
        $account = $publication->socialAccount;

        if (! $account) {
            throw AnalyticsCollectionException::unsupported('Publication has no live social account.');
        }

        return $account;
    }

    /** @param list<MetricValue> $metrics */
    protected function observation(CarbonImmutable $date, array $metrics): PublicationMetricObservation
    {
        if ($metrics === []) {
            throw AnalyticsCollectionException::malformed('Publication metrics response contained no supported measurements.');
        }

        return new PublicationMetricObservation($date, $metrics, collectedAt: CarbonImmutable::now('UTC'));
    }

    protected function count(
        MetricKey $key,
        array $source,
        string $field,
        MetricTimeBasis $timeBasis = MetricTimeBasis::Lifetime,
    ): ?MetricValue {
        $value = data_get($source, $field);

        if (! is_numeric($value)) {
            return null;
        }

        return new MetricValue(
            key: $key,
            value: (int) $value,
            unit: MetricUnit::Count,
            timeBasis: $timeBasis,
            precision: MetricPrecision::Exact,
            availability: MetricAvailability::Available,
            providerMetric: $field,
        );
    }

    protected function decimal(
        MetricKey $key,
        array $source,
        string $field,
        MetricUnit $unit,
        float $multiplier = 1,
        MetricTimeBasis $timeBasis = MetricTimeBasis::Lifetime,
    ): ?MetricValue {
        $value = data_get($source, $field);

        if (! is_numeric($value)) {
            return null;
        }

        return new MetricValue(
            key: $key,
            value: $unit === MetricUnit::Milliseconds
                ? (int) round((float) $value * $multiplier)
                : (float) $value * $multiplier,
            unit: $unit,
            timeBasis: $timeBasis,
            precision: MetricPrecision::Exact,
            availability: MetricAvailability::Available,
            providerMetric: $field,
        );
    }

    /** @return array<string, int|float> */
    protected function insights(array $data): array
    {
        $values = [];

        foreach ($data as $item) {
            $name = data_get($item, 'name');

            if (! is_array($item) || ! is_string($name)) {
                continue;
            }

            $value = data_get($item, 'total_value.value') ?? data_get($item, 'values.0.value');

            if (is_numeric($value)) {
                $values[$name] = $value + 0;
            } elseif (is_array($value) && $value !== []) {
                $numbers = array_filter($value, 'is_numeric');

                if (count($numbers) === count($value)) {
                    $values[$name] = array_sum($numbers);
                }
            }
        }

        return $values;
    }

    /** @param list<MetricValue> $metrics */
    protected function withEngagements(array $metrics): array
    {
        foreach ($metrics as $metric) {
            if ($metric->key === MetricKey::Engagements) {
                return $metrics;
            }

            if ($metric->key === MetricKey::TotalInteractions) {
                $metrics[] = new MetricValue(
                    key: MetricKey::Engagements,
                    value: $metric->value,
                    unit: MetricUnit::Count,
                    timeBasis: $metric->timeBasis,
                    precision: $metric->precision,
                    availability: $metric->availability,
                    providerMetric: $metric->providerMetric,
                );

                return $metrics;
            }
        }

        $interactionKeys = [
            MetricKey::Reactions, MetricKey::Comments, MetricKey::Shares,
            MetricKey::Saves, MetricKey::Quotes, MetricKey::Bookmarks,
            MetricKey::Clicks, MetricKey::LinkClicks,
        ];
        $present = array_filter($metrics, fn (MetricValue $metric): bool => in_array($metric->key, $interactionKeys, true));

        if ($present !== []) {
            $metrics[] = new MetricValue(
                key: MetricKey::Engagements,
                value: array_sum(array_map(fn (MetricValue $metric): int => (int) $metric->value, $present)),
                unit: MetricUnit::Count,
                timeBasis: MetricTimeBasis::Lifetime,
                precision: MetricPrecision::Exact,
                availability: MetricAvailability::Available,
                providerMetric: 'derived_interactions',
            );
        }

        return $metrics;
    }

    /** @param array<int, MetricValue|null> $metrics @return list<MetricValue> */
    protected function present(array $metrics): array
    {
        return array_values(array_filter($metrics, fn (?MetricValue $metric): bool => $metric !== null));
    }
}
