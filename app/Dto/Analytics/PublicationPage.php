<?php

declare(strict_types=1);

namespace App\Dto\Analytics;

final readonly class PublicationPage
{
    /**
     * @param  list<DiscoveredPublication>  $publications
     */
    public function __construct(
        public array $publications,
        public ?string $nextCursor,
        public bool $providerExhausted,
        public bool $providerLimited = false,
        public ?string $partialReason = null,
        public bool $canStopAtTarget = true,
    ) {}
}
