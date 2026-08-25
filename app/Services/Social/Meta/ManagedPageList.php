<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

/**
 * What a page walk found, and whether it found everything.
 *
 * The portfolio edges are additive: failing to read them must not deny a login the
 * Pages `/me/accounts` already returned. But a caller that auto-connects a lone
 * Page would then be binding a workspace off a list it cannot vouch for, so an
 * incomplete walk is carried alongside the Pages rather than swallowed.
 */
final readonly class ManagedPageList
{
    /**
     * @param  list<array<string, mixed>>  $pages
     */
    public function __construct(public array $pages, public bool $complete) {}
}
