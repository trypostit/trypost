<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use RuntimeException;
use Throwable;

/**
 * Thrown when a Meta Graph edge could not be fully fetched — the first page
 * failed, a later page failed, or pagination stopped pathologically. Callers
 * must not treat this as an empty or complete list (e.g. "no pages" or
 * auto-connect when count === 1).
 *
 * `$transient` separates a throttle, an upstream hiccup or a truncated walk —
 * where the real list is unknown — from a confirmed rejection such as a denied
 * permission, where Meta has told us this login reaches nothing on that edge.
 * Only the latter is safe for a caller to read as an empty list; anything
 * unknown defaults to transient.
 *
 * `$fetched` counts the pages that did arrive. A rejection on the very first
 * request means the edge was never readable; a rejection after that means a walk
 * that had started got cut short, and what arrived is a fragment either way.
 */
class IncompleteMetaGraphPaginationException extends RuntimeException
{
    public function __construct(
        ?Throwable $previous = null,
        public readonly bool $transient = true,
        public readonly int $fetched = 0,
    ) {
        parent::__construct('Meta Graph pagination did not complete.', previous: $previous);
    }
}
