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
