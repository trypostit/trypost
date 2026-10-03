<?php

declare(strict_types=1);

namespace App\Dto\Analytics;

use App\Enums\Analytics\MetricPrecision;
use App\Enums\Analytics\ObservationProvenance;
use Carbon\CarbonImmutable;

final readonly class AccountDailyObservation
{
    public function __construct(
        public CarbonImmutable $date,
        public ?int $followers,
        public ObservationProvenance $provenance,
        public MetricPrecision $precision,
        public ?CarbonImmutable $providerObservedAt,
        public ?CarbonImmutable $collectedAt = null,
        public array $metrics = [],
    ) {}
}
