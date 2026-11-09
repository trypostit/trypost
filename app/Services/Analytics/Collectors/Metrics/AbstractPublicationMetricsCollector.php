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
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\AbstractApiPublicationCollector;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Response;

abstract class AbstractPublicationMetricsCollector extends AbstractApiPublicationCollector
{
    abstract public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation;

    protected function account(AnalyticsPublication $publication): SocialAccount
    {
        $account = $publication->socialAccount;

        if (! $account) {
            throw AnalyticsCollectionException::unsupported('Publication has no live social account.');
        }

        return $account;
    }

    /**
     * Runs the post's read and, when it fails in a way `$confirmsDeletion` confirms,
     * reports the post deleted on the network instead of the failure.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $read
     * @param  Closure(AnalyticsCollectionException): bool  $confirmsDeletion
     * @return TResult
     */
    protected function readUnlessDeleted(Closure $read, Closure $confirmsDeletion, Platform $platform): mixed
    {
        try {
            return $read();
        } catch (AnalyticsCollectionException $exception) {
            if ($confirmsDeletion($exception)) {
                throw AnalyticsCollectionException::deletedOnNetwork("{$platform->label()} no longer returns this post.");
            }

            throw $exception;
        }
    }

    /**
     * Whether the post's own read says it is missing. Confirming a deletion takes
     * this and {@see self::stillReads()} on the account's posts, the list its
     * discovery reads, so a token that lost access is never taken for a deleted post.
     *
     * @param  Closure(): Response  $read
     * @param  Closure(AnalyticsCollectionException): bool  $isMissing
     */
    protected function readsMissing(Closure $read, Closure $isMissing): bool
    {
        try {
            $read();

            return false;
        } catch (AnalyticsCollectionException $exception) {
            $this->rethrowWhenTemporary($exception);

            return $isMissing($exception);
        }
    }

    /**
     * @param  Closure(): Response  $read
     */
    protected function stillReads(Closure $read): bool
    {
        try {
            $read();

            return true;
        } catch (AnalyticsCollectionException $exception) {
            $this->rethrowWhenTemporary($exception);

            return false;
        }
    }

    /**
     * A confirming read that failed only for now is rethrown, so the job retries
     * instead of settling the post's read without a confirmation.
     */
    private function rethrowWhenTemporary(AnalyticsCollectionException $exception): void
    {
        if (in_array($exception->category, ['rate_limited', 'transient'], true)) {
            throw $exception;
        }
    }

    /**
     * @param  list<MetricValue>  $metrics
     * @param  array<string, mixed>|null  $providerMetadata
     */
    protected function observation(CarbonImmutable $date, array $metrics, ?array $providerMetadata = null): PublicationMetricObservation
    {
        if ($metrics === []) {
            throw AnalyticsCollectionException::malformed('Publication metrics response contained no supported measurements.');
        }

        return new PublicationMetricObservation($date, $metrics, collectedAt: CarbonImmutable::now('UTC'), providerMetadata: $providerMetadata);
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
            MetricKey::Clicks, MetricKey::LinkClicks, MetricKey::PinClicks, MetricKey::OutboundClicks,
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
