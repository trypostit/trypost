<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use RuntimeException;
use Throwable;

/**
 * Thrown when a Meta Graph edge was only partially fetched (a later page failed
 * after earlier pages succeeded). Callers must not treat the partial list as complete
 * — e.g. auto-connecting when count === 1 would recreate missed-Page bugs.
 */
class IncompleteGraphPaginationException extends RuntimeException
{
    public function __construct(
        string $message = 'Meta Graph pagination did not complete.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
