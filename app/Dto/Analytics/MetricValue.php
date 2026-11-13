<?php

declare(strict_types=1);

namespace App\Dto\Analytics;

use App\Enums\Analytics\MetricAvailability;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricPrecision;
use App\Enums\Analytics\MetricTimeBasis;
use App\Enums\Analytics\MetricUnit;
use Carbon\CarbonImmutable;

final readonly class MetricValue
{
    public function __construct(
        public MetricKey $key,
        public int|float|null $value,
        public MetricUnit $unit,
        public MetricTimeBasis $timeBasis,
        public MetricPrecision $precision,
        public MetricAvailability $availability,
        public ?string $providerMetric = null,
        public ?CarbonImmutable $periodStart = null,
        public ?CarbonImmutable $periodEnd = null,
    ) {}

    /**
     * A metric as stored in a daily snapshot's `metrics` under `$key`, or null
     * when the stored entry is not one this version can read back.
     */
    public static function fromStored(string $key, mixed $stored): ?self
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

        return new self(
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
     * @return array{
     *     value: int|float|null,
     *     unit: string,
     *     time_basis: string,
     *     precision: string,
     *     availability: string,
     *     provider_metric: string|null,
     *     period_start: string|null,
     *     period_end: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'unit' => $this->unit->value,
            'time_basis' => $this->timeBasis->value,
            'precision' => $this->precision->value,
            'availability' => $this->availability->value,
            'provider_metric' => $this->providerMetric,
            'period_start' => $this->periodStart?->toIso8601String(),
            'period_end' => $this->periodEnd?->toIso8601String(),
        ];
    }
}
