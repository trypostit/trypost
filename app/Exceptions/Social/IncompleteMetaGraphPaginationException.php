<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use RuntimeException;
use Throwable;

/**
 * Thrown when a Meta Graph edge was only partially fetched. Callers must not
 * treat a truncated list as complete (e.g. auto-connect when count === 1).
 */
class IncompleteMetaGraphPaginationException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Meta Graph pagination did not complete.', previous: $previous);
    }
}
