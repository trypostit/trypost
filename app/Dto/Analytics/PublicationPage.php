<?php

declare(strict_types=1);

namespace App\Dto\Analytics;

final readonly class PublicationPage
{
    /**
     * @param  list<DiscoveredPublication>  $publications
     * @param  int|null  $providerRowCount  Rows the network returned, including the replies left out of $publications; a provider cap counts these.
     */
    public function __construct(
        public array $publications,
        public ?string $nextCursor,
        public bool $providerExhausted,
        public bool $providerLimited = false,
        public ?string $partialReason = null,
        public bool $canStopAtTarget = true,
        public ?int $providerRowCount = null,
    ) {}

    /**
     * An exhausted, provider-limited page for an account whose grant lacks
     * the scope the history read needs, so no request is made.
     */
    public static function scopeMissing(): self
    {
        return new self([], null, true, true);
    }
}
