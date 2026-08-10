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
 */
class IncompleteMetaGraphPaginationException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Meta Graph pagination did not complete.', previous: $previous);
    }
}
