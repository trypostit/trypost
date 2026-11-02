<?php

declare(strict_types=1);

namespace App\Dto\Analytics;

use Carbon\CarbonImmutable;

final readonly class PublicationMetricObservation
{
    /**
     * @param  list<MetricValue>  $metrics
     * @param  array{options: list<array{label: string, votes: int}>, voting_status: string, end_datetime: ?string, duration_minutes: ?int}|null  $poll
     */
    public function __construct(
        public CarbonImmutable $date,
        public array $metrics,
        public ?CarbonImmutable $providerObservedAt = null,
        public ?CarbonImmutable $collectedAt = null,
        public ?array $poll = null,
    ) {}
}
