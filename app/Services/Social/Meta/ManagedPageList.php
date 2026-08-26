<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

/** What a page walk found, and whether it found everything. */
final readonly class ManagedPageList
{
    /**
     * @param  list<array<string, mixed>>  $pages
     */
    public function __construct(public array $pages, public bool $complete) {}
}
