<?php

declare(strict_types=1);

namespace App\Dto\Analytics;

use Carbon\CarbonImmutable;

final readonly class PublicationMetricObservation
{
    /**
     * @param  list<MetricValue>  $metrics
     * @param  array<string, mixed>|null  $providerMetadata  Keys the network reported about the publication itself, merged into its `provider_metadata`
     */
    public function __construct(
        public CarbonImmutable $date,
        public array $metrics,
        public ?CarbonImmutable $providerObservedAt = null,
        public ?CarbonImmutable $collectedAt = null,
        public ?array $providerMetadata = null,
    ) {}
}
