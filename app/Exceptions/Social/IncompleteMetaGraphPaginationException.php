<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use RuntimeException;
use Throwable;

/**
 * A Meta Graph edge could not be fully fetched. Callers must not read this as an
 * empty or complete list.
 *
 * `$transient` separates a throttle or an upstream hiccup, where the real list is
 * unknown, from a confirmed rejection, where Meta has answered. Unknown by default.
 */
class IncompleteMetaGraphPaginationException extends RuntimeException
{
    public function __construct(?Throwable $previous = null, public readonly bool $transient = true)
    {
        parent::__construct('Meta Graph pagination did not complete.', previous: $previous);
    }
}
